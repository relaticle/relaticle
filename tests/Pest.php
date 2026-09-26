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

use App\Models\User;
use App\Models\Workspace;
use App\Support\Http\HostResolver;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Playwright\Playwright;
use Relaticle\EmailIntegration\Controllers\RedirectController;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;
use Tests\Helpers\PestTiaRuntime;
use Tests\TestCase;

PestTiaRuntime::configure(dirname(__DIR__), array_slice($_SERVER['argv'], 1));

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature', 'Smoke', 'Browser');

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

function insertHistoryImportFailedJob(ConnectedAccount $account, string $batchId, string $uuid, string $messageId = 'failed-message'): void
{
    $job = new StoreEmailJob($account, $messageId);
    $job->withBatchId($batchId);

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => config('queue.default'),
        'queue' => 'emails-sync',
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

function bindMailboxOAuthWorkspace(User $user, ?Workspace $team = null): void
{
    $team ??= $user->currentWorkspace;

    throw_unless($team instanceof Workspace, RuntimeException::class, 'bindMailboxOAuthWorkspace requires a workspace.');

    session()->put(RedirectController::WORKSPACE_SESSION_KEY, $team->getKey());
}

function mailboxOAuthRedirectUrl(string $provider, Workspace $team): string
{
    return MailboxOAuthWorkspace::redirectUrl($provider, $team);
}

function assertMailboxOAuthRedirectUrl(string $url, string $provider, Workspace $team): void
{
    expect($url)->toContain("/email-accounts/redirect/{$provider}");

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query['team'] ?? null)->toBe($team->getKey())
        ->and(Request::create($url)->hasValidSignature())->toBeTrue();
}

function assertRedirectedToMailboxOAuth(Testable $component, string $provider, Workspace $team): void
{
    $component->assertRedirect();

    /** @var string $redirect */
    $redirect = $component->effects['redirect'];

    assertMailboxOAuthRedirectUrl(url($redirect), $provider, $team);
}

function assertActionHasMailboxOAuthUrl(
    Testable $component,
    string|TestAction|array $action,
    string $provider,
    Workspace $team,
): void {
    $component->assertActionExists(
        $action,
        checkActionUsing: function (Action $resolvedAction) use ($provider, $team): bool {
            try {
                assertMailboxOAuthRedirectUrl((string) $resolvedAction->getUrl(), $provider, $team);

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
function loginViaBrowser(User $user): AwaitableWebpage
{
    return test()->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->click('button[type="submit"]')
        ->type('[id="form.password"]', 'password')
        ->click('button[type="submit"]');
}

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
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
