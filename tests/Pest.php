<?php

declare(strict_types=1);

/**
 * Test Suite Architecture (Testing Trophy)
 *
 * Layer 0: Static Analysis (PHPStan, Pint, Rector, Type Coverage 100%)
 * Layer 1: Architecture Tests (tests/Arch/)
 * Layer 2: Smoke Tests (tests/Smoke/) -- HTTP-level route smoke
 * Layer 3: Workflow Tests (tests/Feature/) -- bulk of suite
 * Layer 4: Browser Tests (tests/Browser/) -- critical paths only
 *
 * Conventions: see CLAUDE.md -> Testing section
 */

use App\Enums\Notifications\NotificationReason;
use App\Enums\WorkspaceSetupStep;
use App\Features\Billing;
use App\Filament\Pages\CreateWorkspace;
use App\Filament\Pages\SetupWorkspace;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Http\HostResolver;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Playwright\Playwright;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\EmailIntegration\Controllers\RedirectController;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;
use Tests\Helpers\ChatCatalog;
use Tests\Helpers\PestTiaRuntime;
use Tests\TestCase;

PestTiaRuntime::configure(dirname(__DIR__), array_slice($_SERVER['argv'], 1));

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature', 'Smoke', 'Browser', '../packages/Cloud/tests');

if (class_exists(Playwright::class)) {
    Playwright::setTimeout(30_000);
}

/**
 * Livewire testing helper - replacement for pest-plugin-livewire.
 *
 * @param  class-string  $component
 * @param  array<string, mixed>  $params
 */
function livewire(string $component, array $params = []): Testable
{
    return Livewire::test($component, $params);
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function flashedNotifications(): Collection
{
    $mailbox = resolve(NotificationMailbox::class);
    $mailbox->flush();

    return collect($mailbox->pull());
}

/**
 * Invoke the chat conversation broadcast channel authorization callback.
 *
 * The conversation channel is registered on boot; if the broadcaster has not yet
 * loaded it (rare worker-restart scenarios in tests), we re-require the package
 * channel routes file once and retry.
 */
function chatChannelAuth(User $user, string $conversationId): bool
{
    $broadcaster = app(BroadcasterContract::class);
    $reflection = new ReflectionClass($broadcaster);
    $prop = $reflection->getProperty('channels');
    $prop->setAccessible(true);
    $channels = $prop->getValue($broadcaster);

    $callback = $channels['chat.conversation.{conversationId}'] ?? null;

    if ($callback === null) {
        require __DIR__.'/../packages/Chat/routes/channels.php';

        return chatChannelAuth($user, $conversationId);
    }

    return (bool) $callback($user, $conversationId);
}

/**
 * Invoke the App.Models.User.{id} broadcast channel authorization callback.
 *
 * Mirrors chatChannelAuth. Retrieves the registered closure via reflection
 * and invokes it directly, so tests exercise the real production callback.
 */
function userChannelAuth(User $user, string $id): bool
{
    $broadcaster = app(BroadcasterContract::class);
    $reflection = new ReflectionClass($broadcaster);
    $prop = $reflection->getProperty('channels');
    $prop->setAccessible(true);
    $channels = $prop->getValue($broadcaster);

    $callback = $channels['App.Models.User.{id}'] ?? null;

    if ($callback === null) {
        require base_path('routes/channels.php');

        return userChannelAuth($user, $id);
    }

    return (bool) $callback($user, $id);
}

function attachHistoryImportBatch(ConnectedAccount $account): string
{
    $batch = resolve(MailboxHistoryImportService::class)->startBatch($account);
    $account->update(['history_import_batch_id' => $batch->id]);

    return $batch->id;
}

function mailboxOwnerInWorkspace(ConnectedAccount $account, string $role = 'admin'): User
{
    $owner = $account->user;
    $owner->workspaces()->syncWithoutDetaching([$account->workspace_id => ['role' => $role]]);
    $owner->forceFill(['current_workspace_id' => $account->workspace_id])->save();

    return $owner->fresh();
}

function insertHistoryImportFailedJob(ConnectedAccount $account, string $batchId, string $uuid, string $messageId = 'failed-message', string $queue = 'emails-import'): void
{
    $job = new StoreEmailJob($account, $messageId);
    $job->withBatchId($batchId);

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => config('queue.default'),
        'queue' => $queue,
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => StoreEmailJob::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => StoreEmailJob::class,
                'command' => serialize($job),
            ],
        ]),
        'exception' => 'RuntimeException: Provider unavailable',
        'failed_at' => now(),
    ]);
}

function fakeHistoryImportQueueRetry(string $uuid): void
{
    Artisan::shouldReceive('call')
        ->once()
        ->with('queue:retry', ['id' => $uuid])
        ->andReturnUsing(function () use ($uuid): int {
            DB::table('failed_jobs')->where('uuid', $uuid)->delete();

            return 0;
        });
}

function setHistoryImportBatchProgress(string $batchId, int $totalJobs, int $pendingJobs): void
{
    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => $totalJobs,
        'pending_jobs' => $pendingJobs,
        'failed_jobs' => 0,
        'finished_at' => null,
    ]);
}

function bindMailboxOAuthWorkspace(User $user, ?Workspace $workspace = null): void
{
    $workspace ??= $user->currentWorkspace;

    throw_unless($workspace instanceof Workspace, RuntimeException::class, 'bindMailboxOAuthWorkspace requires a workspace.');

    session()->put(RedirectController::WORKSPACE_SESSION_KEY, $workspace->getKey());
}

/** @param  array<string, mixed>  $attributes */
function pendingEmailAccessRequest(User $owner, Workspace $workspace, string $subject, string $requesterName, EmailAccessRequestStatus $status = EmailAccessRequestStatus::PENDING, array $attributes = []): EmailAccessRequest
{
    $requester = User::factory()->create(['name' => $requesterName]);
    $workspace->users()->attach($requester, ['role' => 'member']);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]));

    $email = Email::factory()->private()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->getKey(),
        'subject' => $subject,
    ]);

    return EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => $requester->id,
        'owner_id' => $owner->id,
        'email_id' => $email->getKey(),
        'status' => $status,
        ...$attributes,
    ]);
}

function denyRequestMidDecision(EmailAccessRequest $request): void
{
    $loads = 0;

    EmailAccessRequest::retrieved(function (EmailAccessRequest $retrieved) use ($request, &$loads): void {
        if ($retrieved->isNot($request) || ++$loads < 2) {
            return;
        }

        Event::forget('eloquent.retrieved: '.EmailAccessRequest::class);

        DB::table('email_access_requests')->where('id', $request->getKey())->update(['status' => EmailAccessRequestStatus::DENIED->value]);
    });
}

function bellAccessRequestNotice(User $owner, EmailAccessRequest $request): NotificationRow
{
    return NotificationRow::factory()->create([
        'type' => EmailAccessRequestedNotification::class,
        'notifiable_type' => $owner->getMorphClass(),
        'notifiable_id' => $owner->getKey(),
        'data' => [
            'title' => 'Requester requested email access',
            'body' => $request->email?->subject,
            'viewData' => ['request_id' => (string) $request->getKey()],
        ],
    ]);
}

function inboxAccessRequestNotice(User $owner, EmailAccessRequest $request): NotificationRow
{
    return NotificationRow::factory()->create([
        'type' => EmailAccessRequestedNotification::class,
        'notifiable_type' => $owner->getMorphClass(),
        'notifiable_id' => $owner->getKey(),
        'workspace_id' => $request->workspace_id,
        'actor_id' => $request->requester_id,
        'reason' => NotificationReason::Requested,
        'subject_type' => $request->getMorphClass(),
        'subject_id' => $request->getKey(),
        'data' => [
            'email_subject' => $request->email?->subject,
            'email_id' => $request->email_id,
        ],
    ]);
}

function mailboxOAuthRedirectUrl(string $provider, Workspace $workspace): string
{
    return MailboxOAuthWorkspace::redirectUrl($provider, $workspace);
}

function assertMailboxOAuthRedirectUrl(string $url, string $provider, Workspace $workspace): void
{
    expect($url)->toContain("/email-accounts/redirect/{$provider}");

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query['workspace'] ?? null)->toBe($workspace->getKey())
        ->and(Request::create($url)->hasValidSignature())->toBeTrue();
}

function assertRedirectedToMailboxOAuth(Testable $component, string $provider, Workspace $workspace): void
{
    $component->assertRedirect();

    /** @var string $redirect */
    $redirect = $component->effects['redirect'];

    assertMailboxOAuthRedirectUrl(url($redirect), $provider, $workspace);
}

function assertActionHasMailboxOAuthUrl(
    Testable $component,
    string|TestAction|array $action,
    string $provider,
    Workspace $workspace,
): void {
    $component->assertActionExists(
        $action,
        checkActionUsing: function (Action $resolvedAction) use ($provider, $workspace): bool {
            try {
                assertMailboxOAuthRedirectUrl((string) $resolvedAction->getUrl(), $provider, $workspace);

                return true;
            } catch (Throwable) {
                return false;
            }
        },
    );
}

/**
 * Log in through the real two-step login form: type the email and submit to
 * reveal the password field, then type the password and submit again.
 */
function loginViaBrowser(User $user, ?string $theme = null): AwaitableWebpage
{
    $page = test()->visit('/app/login');

    if ($theme !== null) {
        $page = $page->{$theme}();
    }

    return $page
        ->type('[id="form.email"]', $user->email)
        ->click('button[type="submit"]')
        ->assertVisible('[id="form.password"]')
        ->assertScript(asyncAlpineComponentsLoaded())
        ->type('[id="form.password"]', 'password')
        ->click('button[type="submit"]');
}

function asyncAlpineComponentsLoaded(): string
{
    return 'document.querySelector("[x-load][x-ignore]") === null';
}

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

function fakeDiskWithoutLocalPaths(string $disk): FilesystemAdapter
{
    $fake = Storage::fake($disk);

    $remote = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
    {
        public function path(mixed $path): string
        {
            return '/nonexistent-remote-disk/'.ltrim((string) $path, '/');
        }
    };

    Storage::set($disk, $remote);

    return $remote;
}

function useRemoteImportStore(): void
{
    config()->set('import-wizard.store.disk', 's3');
    fakeDiskWithoutLocalPaths('s3');
}

function importStoreLockName(string $importId): string
{
    return "import-store:{$importId}";
}

function onePixelPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
}

function signedUrlSignature(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) ($query['signature'] ?? '');
}

/** @param list<string> $addresses */
function resolveHostsTo(array $addresses, int &$calls = 0): void
{
    $calls = 0;

    app()->instance(HostResolver::class, new HostResolver(function (string $host) use ($addresses, &$calls): array {
        $calls++;

        return $addresses;
    }));
}

/** @param list<array<string, mixed>> $toolResults */
function storedToolSteps(array $toolResults, string $content = ''): string
{
    return json_encode([[
        'content' => $content,
        'tool_calls' => array_map(static fn (array $toolResult): array => ['arguments' => [], ...$toolResult], $toolResults),
        'reasoning' => '',
        'replay_blocks' => [],
        'provider_tool_calls' => [],
    ]], JSON_THROW_ON_ERROR);
}

/** @param  list<string>  $scopes */
function actAsOAuthClient(User $user, array $scopes, ?Workspace $workspace): void
{
    $client = Client::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'name' => 'REST Connector',
        'redirect_uris' => ['https://example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
    ]);

    $tokenId = Str::random(80);

    DB::table('oauth_access_tokens')->insert([
        'id' => $tokenId,
        'user_id' => $user->getKey(),
        'client_id' => $client->getKey(),
        'workspace_id' => $workspace?->getKey(),
        'name' => 'REST Connector',
        'scopes' => json_encode($scopes),
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDays(30),
    ]);

    $user->withAccessToken(new AccessToken([
        'oauth_access_token_id' => $tokenId,
        'oauth_client_id' => $client->getKey(),
        'oauth_user_id' => $user->getKey(),
        'oauth_scopes' => $scopes,
    ]));

    auth()->guard('api')->setUser($user);
    auth()->shouldUse('api');
}

/**
 * @param  array<string, mixed>  $workspace
 * @param  array<string, mixed>  $useCase
 */
function onboardWorkspace(User $user, array $workspace, array $useCase = ['onboarding_use_case' => 'other']): Workspace
{
    test()->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm($workspace)
        ->call('register')
        ->assertHasNoFormErrors();

    $created = $user->fresh()->currentWorkspace;

    Filament::setTenant($created);

    $setup = livewire(SetupWorkspace::class);

    if ($created->onboarding_step === WorkspaceSetupStep::Email) {
        $setup->callAction('skipMailbox');
    }

    $setup->fillForm($useCase)->call('saveUseCase')->assertHasNoFormErrors();

    if ($created->fresh()->onboarding_step === WorkspaceSetupStep::Invite) {
        $setup->call('finish');
    }

    return $created->fresh();
}

function useProFirstAutoChain(): void
{
    config()->set('chat.models', [
        ChatCatalog::entry(['label' => 'Opus 5', 'model' => 'claude-opus-5', 'min_plan' => 'pro', 'credit_multiplier' => 3.0]),
        ChatCatalog::entry(),
    ]);
    app()->forgetInstance(ModelRegistry::class);
}

function withHostedBilling(): void
{
    if (! class_exists('Relaticle\Cloud\CloudServiceProvider')) {
        test()->markTestSkipped('Hosted billing needs the cloud package.');
    }

    Feature::define(Billing::class, true);
}
