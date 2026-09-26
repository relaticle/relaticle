<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Relaticle\EmailIntegration\Actions\ConnectAccountAction;
use Relaticle\EmailIntegration\Actions\DisconnectConnectedAccountAction;
use Relaticle\EmailIntegration\Actions\StartMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Controllers\CallbackController;
use Relaticle\EmailIntegration\Controllers\RedirectController;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;

mutates(AppServiceProvider::class);
mutates(CallbackController::class);
mutates(ConnectAccountAction::class);
mutates(DisconnectConnectedAccountAction::class);
mutates(InitialEmailSyncJob::class);
mutates(RedirectController::class);
mutates(StartMailboxHistoryImportAction::class);
mutates(MailboxHistoryImportService::class);

it('resolves the azure socialite driver', function (): void {
    expect(fn () => Socialite::driver('azure'))->not->toThrow(Throwable::class);
});

it('stores an azure connected account and flips calendar capability when Graph calendar scope is granted', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $social = new SocialiteUser;
    $social->id = 'azure-123';
    $social->email = 'ms@example.com';
    $social->name = 'MS Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://graph.microsoft.com/Mail.Read',
        'https://graph.microsoft.com/Mail.Send',
        'https://graph.microsoft.com/Calendars.Read',
        'offline_access',
    ];

    Socialite::fake('azure', $social);
    bindMailboxOAuthWorkspace($user);

    $this->get(route('email-accounts.callback', ['provider' => 'azure']))
        ->assertRedirect();

    $account = ConnectedAccount::query()
        ->where('email_address', 'ms@example.com')
        ->where('provider', EmailProvider::AZURE)
        ->firstOrFail();

    expect($account->hasCalendar())->toBeTrue()
        ->and($account->capabilities['email'])->toBeTrue()
        ->and($account->hasSend())->toBeTrue()
        ->and($user->currentWorkspace->fresh()->contact_creation_mode)->toBe(ContactCreationMode::Selective);

    Bus::assertDispatched(InitialCalendarSyncJob::class, fn (InitialCalendarSyncJob $job): bool => $job->connectedAccount->is($account));
    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
    Bus::assertDispatched(RelinkMailboxHistoryJob::class, fn (RelinkMailboxHistoryJob $job): bool => $job->connectedAccount->is($account));
});

it('flips calendar capability when Graph grants Calendars.ReadWrite without Calendars.Read', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $social = new SocialiteUser;
    $social->id = 'azure-write';
    $social->email = 'ms-write@example.com';
    $social->name = 'MS Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://graph.microsoft.com/Mail.Read',
        'https://graph.microsoft.com/Calendars.ReadWrite',
        'offline_access',
    ];

    Socialite::fake('azure', $social);
    bindMailboxOAuthWorkspace($user);

    $this->get(route('email-accounts.callback', ['provider' => 'azure']))
        ->assertRedirect();

    $account = ConnectedAccount::query()
        ->where('email_address', 'ms-write@example.com')
        ->where('provider', EmailProvider::AZURE)
        ->firstOrFail();

    expect($account->hasCalendar())->toBeTrue();

    Bus::assertDispatched(InitialCalendarSyncJob::class, fn (InitialCalendarSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('flips calendar and send capabilities when Graph returns unqualified scope names', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $social = new SocialiteUser;
    $social->id = 'azure-unqualified';
    $social->email = 'ms-unqualified@example.com';
    $social->name = 'MS Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'Mail.Read',
        'Mail.Send',
        'Calendars.ReadWrite',
        'offline_access',
    ];

    Socialite::fake('azure', $social);
    bindMailboxOAuthWorkspace($user);

    $this->get(route('email-accounts.callback', ['provider' => 'azure']))
        ->assertRedirect();

    $account = ConnectedAccount::query()
        ->where('email_address', 'ms-unqualified@example.com')
        ->where('provider', EmailProvider::AZURE)
        ->firstOrFail();

    expect($account->hasCalendar())->toBeTrue()
        ->and($account->hasSend())->toBeTrue()
        ->and($account->hasEmail())->toBeTrue();

    Bus::assertDispatched(InitialCalendarSyncJob::class, fn (InitialCalendarSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('records send as missing when Graph does not grant Mail.Send', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $social = new SocialiteUser;
    $social->id = 'azure-no-send';
    $social->email = 'ms-no-send@example.com';
    $social->name = 'MS Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://graph.microsoft.com/Mail.Read',
        'https://graph.microsoft.com/Calendars.Read',
        'offline_access',
    ];

    Socialite::fake('azure', $social);
    bindMailboxOAuthWorkspace($user);

    $this->get(route('email-accounts.callback', ['provider' => 'azure']))
        ->assertRedirect();

    $account = ConnectedAccount::query()
        ->where('email_address', 'ms-no-send@example.com')
        ->where('provider', EmailProvider::AZURE)
        ->firstOrFail();

    expect($account->hasSend())->toBeFalse()
        ->and($account->hasEmail())->toBeTrue();
});

it('preserves the stored refresh token when a reconnect returns none', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $connect = function (?string $refreshToken) use ($user): ConnectedAccount {
        $social = new SocialiteUser;
        $social->id = 'gmail-reconnect';
        $social->email = 'reconnect@example.com';
        $social->name = 'Demo';
        $social->token = 'access-'.($refreshToken ?? 'none');
        $social->refreshToken = $refreshToken;
        $social->expiresIn = 3600;
        $social->approvedScopes = [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
        ];

        Socialite::fake('gmail', $social);
        bindMailboxOAuthWorkspace($user);

        $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

        return ConnectedAccount::query()
            ->where('user_id', $user->getKey())
            ->where('email_address', 'reconnect@example.com')
            ->firstOrFail();
    };

    $connect('original-refresh');
    $account = $connect(null);

    expect($account->refresh()->refresh_token)->toBe('original-refresh');

    Bus::assertDispatchedTimes(InitialEmailSyncJob::class, 1);
});

it('restarts history import when an active mailbox is reconnected', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $connect = function () use ($user): ConnectedAccount {
        $social = new SocialiteUser;
        $social->id = 'gmail-live-reconnect';
        $social->email = 'live-reconnect@example.com';
        $social->name = 'Demo';
        $social->token = 'access-token';
        $social->refreshToken = 'refresh-token';
        $social->expiresIn = 3600;
        $social->approvedScopes = [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
        ];

        Socialite::fake('gmail', $social);
        bindMailboxOAuthWorkspace($user);

        $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

        return ConnectedAccount::query()
            ->where('user_id', $user->getKey())
            ->where('email_address', 'live-reconnect@example.com')
            ->firstOrFail();
    };

    $account = $connect();
    $account->update([
        'sync_cursor' => 'history-done',
        'history_import_batch_id' => 'batch-1',
    ]);

    $account = $connect();

    expect($account->sync_cursor)->toBeNull()
        ->and($account->history_import_batch_id)->not->toBe('batch-1')
        ->and($account->history_import_batch_id)->not->toBeNull();

    Bus::assertDispatchedTimes(InitialEmailSyncJob::class, 2);
    Bus::assertDispatchedTimes(RelinkMailboxHistoryJob::class, 2);
});

it('dispatches history import when a disconnected account is reconnected', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->trashed()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $user->current_workspace_id,
        'email_address' => 'again@example.com',
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'gmail-reconnect-import',
    ]));

    $social = new SocialiteUser;
    $social->id = 'gmail-reconnect-import';
    $social->email = 'again@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($user);
    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    expect($account->refresh()->trashed())->toBeFalse();

    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
    Bus::assertDispatched(RelinkMailboxHistoryJob::class, fn (RelinkMailboxHistoryJob $job): bool => $job->connectedAccount->is($account));
});

it('dispatches history import when reconnecting a mailbox whose listing stopped', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $user->current_workspace_id,
        'email_address' => 'stopped-listing@example.com',
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'gmail-reconnect-stopped-listing',
        'sync_cursor' => null,
    ]));
    $staleBatchId = attachHistoryImportBatch($account);

    (new InitialEmailSyncJob($account, historyImportBatchId: $staleBatchId))
        ->failed(new RuntimeException('Provider 500'));

    resolve(DisconnectConnectedAccountAction::class)->execute($account->fresh());

    Bus::fake();

    $social = new SocialiteUser;
    $social->id = 'gmail-reconnect-stopped-listing';
    $social->email = 'stopped-listing@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($user);
    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    $account->refresh();

    expect($account->trashed())->toBeFalse()
        ->and($account->history_import_batch_id)->not->toBe($staleBatchId)
        ->and($account->history_import_batch_id)->not->toBeNull();

    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('dispatches history import when reauthenticating a live mailbox whose listing stopped', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $user->current_workspace_id,
        'email_address' => 'live-stopped-listing@example.com',
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'gmail-live-stopped-listing',
        'sync_cursor' => null,
    ]));
    $staleBatchId = attachHistoryImportBatch($account);

    (new InitialEmailSyncJob($account, historyImportBatchId: $staleBatchId))
        ->failed(new RuntimeException('Provider 500'));

    expect($account->fresh()->status)->toBe(EmailAccountStatus::ERROR)
        ->and($account->fresh()->trashed())->toBeFalse();

    Bus::fake();

    $social = new SocialiteUser;
    $social->id = 'gmail-live-stopped-listing';
    $social->email = 'live-stopped-listing@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($user);
    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    $account->refresh();

    expect($account->trashed())->toBeFalse()
        ->and($account->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->history_import_batch_id)->not->toBe($staleBatchId)
        ->and($account->history_import_batch_id)->not->toBeNull();

    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('dispatches history import when reauthenticating a mailbox that already finished listing', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $user->current_workspace_id,
        'email_address' => 'reauth-done@example.com',
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'gmail-reauth-done',
        'sync_cursor' => 'history-done',
        'history_import_batch_id' => 'batch-before-reauth',
        'status' => EmailAccountStatus::REAUTH_REQUIRED,
    ]));

    $social = new SocialiteUser;
    $social->id = 'gmail-reauth-done';
    $social->email = 'reauth-done@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($user);
    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    $account->refresh();

    expect($account->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->history_import_batch_id)->not->toBe('batch-before-reauth')
        ->and($account->history_import_batch_id)->not->toBeNull()
        ->and($account->sync_cursor)->toBeNull();

    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
    Bus::assertDispatched(RelinkMailboxHistoryJob::class, fn (RelinkMailboxHistoryJob $job): bool => $job->connectedAccount->is($account));
});

it('does not start a second history import when reauthenticating during listing', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $user->current_workspace_id,
        'email_address' => 'listing-in-progress@example.com',
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'gmail-listing-in-progress',
        'sync_cursor' => null,
    ]));
    $batchId = attachHistoryImportBatch($account);

    expect(resolve(MailboxHistoryImportService::class)->isRunning($account->fresh()))->toBeTrue();

    Queue::fake();

    $social = new SocialiteUser;
    $social->id = 'gmail-listing-in-progress';
    $social->email = 'listing-in-progress@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($user);
    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    expect($account->fresh()->history_import_batch_id)->toBe($batchId);

    Queue::assertNotPushed(InitialEmailSyncJob::class);
    Queue::assertNotPushed(RelinkMailboxHistoryJob::class);
});

it('keeps a failed listing failed while store jobs drain and recovers after they finish', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $user->current_workspace_id,
        'email_address' => 'drain-then-recover@example.com',
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'gmail-drain-then-recover',
        'sync_cursor' => null,
    ]));
    $batchId = attachHistoryImportBatch($account);

    (new InitialEmailSyncJob($account, historyImportBatchId: $batchId))
        ->failed(new RuntimeException('Provider 500'));

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 4,
        'pending_jobs' => 2,
        'failed_jobs' => 0,
        'finished_at' => null,
    ]);

    expect($account->fresh()->status)->toBe(EmailAccountStatus::ERROR)
        ->and(resolve(MailboxHistoryImportService::class)->isRunning($account->fresh()))->toBeTrue();

    Queue::fake();

    $social = new SocialiteUser;
    $social->id = 'gmail-drain-then-recover';
    $social->email = 'drain-then-recover@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($user);
    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    $account->refresh();

    expect($account->status)->toBe(EmailAccountStatus::ERROR)
        ->and($account->last_error)->toBe('Provider 500')
        ->and($account->history_import_batch_id)->toBe($batchId);

    Queue::assertNotPushed(InitialEmailSyncJob::class);
    Queue::assertNotPushed(RelinkMailboxHistoryJob::class);

    DB::table('job_batches')->where('id', $batchId)->update([
        'pending_jobs' => 0,
        'finished_at' => now()->getTimestamp(),
    ]);

    expect(resolve(MailboxHistoryImportService::class)->isRunning($account->fresh()))->toBeFalse();

    bindMailboxOAuthWorkspace($user);
    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    $account->refresh();

    expect($account->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->history_import_batch_id)->not->toBe($batchId)
        ->and($account->history_import_batch_id)->not->toBeNull();

    Queue::assertPushed(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('connects the mailbox to the workspace where authorization started after the current workspace changes', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $initiatingTeam = $user->currentWorkspace;
    $otherTeam = Workspace::factory()->create(['user_id' => $user->getKey()]);
    $user->workspaces()->attach($otherTeam, ['role' => 'admin']);

    $this->actingAs($user);

    config()->set('services.gmail.client_id', 'gmail-client-id');
    config()->set('services.gmail.client_secret', 'gmail-client-secret');
    config()->set('services.gmail.redirect', 'http://localhost/email-accounts/callback/gmail');

    $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $initiatingTeam))
        ->assertRedirect();

    $user->forceFill(['current_workspace_id' => $otherTeam->getKey()])->save();
    $user->unsetRelation('currentWorkspace');

    $social = new SocialiteUser;
    $social->id = 'gmail-workspace-bind';
    $social->email = 'bind@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect(EmailAccountsPage::getUrl([
            'tenant' => $initiatingTeam->slug,
        ], panel: 'app'));

    $this->assertDatabaseHas(ConnectedAccount::class, [
        'email_address' => 'bind@example.com',
        'workspace_id' => $initiatingTeam->getKey(),
    ]);
    $this->assertDatabaseMissing(ConnectedAccount::class, [
        'email_address' => 'bind@example.com',
        'workspace_id' => $otherTeam->getKey(),
    ]);
});

it('does not connect a mailbox when the user left the authorizing workspace', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $foreignTeam = Workspace::factory()->create();
    $user->workspaces()->attach($foreignTeam, ['role' => 'admin']);
    $user->forceFill(['current_workspace_id' => $foreignTeam->getKey()])->save();
    $user->unsetRelation('currentWorkspace');

    $this->actingAs($user);

    config()->set('services.gmail.client_id', 'gmail-client-id');
    config()->set('services.gmail.client_secret', 'gmail-client-secret');
    config()->set('services.gmail.redirect', 'http://localhost/email-accounts/callback/gmail');

    $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $foreignTeam))
        ->assertRedirect();

    $user->workspaces()->detach($foreignTeam);
    $user->forceFill(['current_workspace_id' => $user->ownedWorkspaces()->first()?->getKey()])->save();
    $user->unsetRelation('currentWorkspace');
    $user->unsetRelation('workspaces');

    $social = new SocialiteUser;
    $social->id = 'gmail-left-workspace';
    $social->email = 'left@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect()
        ->assertSessionHas('error', 'Your sign-in session expired. Please reconnect the account.');

    $this->assertDatabaseMissing(ConnectedAccount::class, [
        'email_address' => 'left@example.com',
    ]);
});

it('stores a separate connected account when the same mailbox is connected in a second workspace', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $firstTeam = $user->currentWorkspace;
    $secondTeam = Workspace::factory()->create(['user_id' => $user->getKey()]);
    $user->workspaces()->attach($secondTeam, ['role' => 'admin']);

    $connect = function () use ($user): void {
        $social = new SocialiteUser;
        $social->id = 'gmail-shared-mailbox';
        $social->email = 'shared@example.com';
        $social->name = 'Demo';
        $social->token = 'access-token';
        $social->refreshToken = 'refresh-token';
        $social->expiresIn = 3600;
        $social->approvedScopes = [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
        ];

        Socialite::fake('gmail', $social);
        bindMailboxOAuthWorkspace($user);

        $this->actingAs($user)
            ->get(route('email-accounts.callback', ['provider' => 'gmail']))
            ->assertRedirect();
    };

    $connect();

    $user->forceFill(['current_workspace_id' => $secondTeam->getKey()])->save();
    $user->unsetRelation('currentWorkspace');
    $connect();

    $accounts = ConnectedAccount::query()
        ->where('user_id', $user->getKey())
        ->where('email_address', 'shared@example.com')
        ->get();

    expect($accounts)->toHaveCount(2)
        ->and($accounts->pluck('workspace_id')->all())->toEqualCanonicalizing([
            $firstTeam->getKey(),
            $secondTeam->getKey(),
        ]);
});

it('reconnects a previously default mailbox without violating the live default constraint', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    $team = $user->currentWorkspace;

    $default = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->default()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $team->getKey(),
        'email_address' => 'default@example.com',
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'gmail-default-reconnect',
    ]));

    $successor = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $team->getKey(),
    ]));

    app(DisconnectConnectedAccountAction::class)->execute($default);

    expect($successor->fresh()->is_default)->toBeTrue();

    $social = new SocialiteUser;
    $social->id = 'gmail-default-reconnect';
    $social->email = 'default@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    expect($default->refresh()->trashed())->toBeFalse()
        ->and($default->is_default)->toBeFalse()
        ->and($successor->fresh()->is_default)->toBeTrue();
});

it('makes the first connected account the default and leaves later connections non-default', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $connect = function (string $email) use ($user): ConnectedAccount {
        $social = new SocialiteUser;
        $social->id = "gmail-{$email}";
        $social->email = $email;
        $social->name = 'Demo';
        $social->token = 'access-token';
        $social->refreshToken = 'refresh-token';
        $social->expiresIn = 3600;
        $social->approvedScopes = [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
        ];

        Socialite::fake('gmail', $social);
        bindMailboxOAuthWorkspace($user);

        $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

        return ConnectedAccount::query()
            ->where('user_id', $user->getKey())
            ->where('email_address', $email)
            ->firstOrFail();
    };

    $first = $connect('first@example.com');
    $second = $connect('second@example.com');

    expect($first->refresh()->is_default)->toBeTrue()
        ->and($second->refresh()->is_default)->toBeFalse();
});
