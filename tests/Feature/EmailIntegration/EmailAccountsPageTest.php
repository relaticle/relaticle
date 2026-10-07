<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Relaticle\EmailIntegration\Actions\ConnectAccountAction;
use Relaticle\EmailIntegration\Actions\DisconnectConnectedAccountAction;
use Relaticle\EmailIntegration\Data\ConnectAccountData;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectedAccountActions;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountSettingsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailSignaturesPage;
use Relaticle\EmailIntegration\Filament\Pages\UserEmailPrivacyPage;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;
use Relaticle\EmailIntegration\Services\ProviderGrantRevoker;

mutates(EmailAccountsPage::class, ConnectedAccount::class, HasConnectedAccountActions::class, DisconnectConnectedAccountAction::class, ProviderGrantRevoker::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
});

it('links reconnect to the mailbox oauth redirect for the account provider', function (EmailProvider $provider): void {
    $this->account->update(['provider' => $provider]);

    $component = livewire(EmailAccountsPage::class)
        ->assertActionVisible(TestAction::make('reconnect')->arguments(['account_id' => $this->account->id]));

    assertActionHasMailboxOAuthUrl(
        $component,
        TestAction::make('reconnect')->arguments(['account_id' => $this->account->id]),
        $provider->value,
        $this->workspace,
    );

    $component->assertActionDoesNotExist('reAuth');
})->with([
    'gmail' => EmailProvider::GMAIL,
    'microsoft' => EmailProvider::AZURE,
]);

it('keeps reconnect visible when the mailbox cannot send', function (): void {
    $this->account->update(
        ConnectedAccount::factory()->withoutSend()->make()->only(['capabilities']),
    );

    livewire(EmailAccountsPage::class)
        ->assertActionVisible(TestAction::make('reconnect')->arguments(['account_id' => $this->account->id]));
});

it('keeps reconnect available when the mailbox has a sync error', function (): void {
    $this->account->update(
        ConnectedAccount::factory()->error()->make()->only(['status', 'last_error']),
    );

    $component = livewire(EmailAccountsPage::class)
        ->assertActionVisible(TestAction::make('reconnect')->arguments(['account_id' => $this->account->id]));

    assertActionHasMailboxOAuthUrl(
        $component,
        TestAction::make('reconnect')->arguments(['account_id' => $this->account->id]),
        EmailProvider::GMAIL->value,
        $this->workspace,
    );

    $component->assertActionDoesNotExist('reAuth');
});

it('keeps reconnect available when re-authentication is required', function (): void {
    $this->account->update(['status' => EmailAccountStatus::REAUTH_REQUIRED]);

    livewire(EmailAccountsPage::class)
        ->assertActionVisible(TestAction::make('reconnect')->arguments(['account_id' => $this->account->id]))
        ->assertActionDoesNotExist('reAuth');
});

it('does not expose reconnect for another user\'s account', function (): void {
    $otherUser = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $otherUser->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->assertActionHidden(TestAction::make('reconnect')->arguments(['account_id' => $otherAccount->id]));
});

it('links accountSettings to the per-account settings page', function (): void {
    livewire(EmailAccountsPage::class)
        ->assertActionHasUrl(
            TestAction::make('accountSettings')->arguments(['account_id' => $this->account->id]),
            EmailAccountSettingsPage::getUrl(['account' => $this->account->id]),
        );
});

it('deletes the authenticated user\'s account on disconnect', function (): void {
    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response()]);
    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $this->account->id]);

    $this->assertSoftDeleted(ConnectedAccount::class, [
        'id' => $this->account->id,
    ]);
});

it('revokes the google grant and clears the stored tokens on disconnect', function (): void {
    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response()]);
    $this->account->update(['provider' => EmailProvider::GMAIL, 'refresh_token' => 'refresh-to-revoke']);

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $this->account->id]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'
        && $request->isForm()
        && $request['token'] === 'refresh-to-revoke');

    $disconnected = ConnectedAccount::withTrashed()->findOrFail($this->account->id);

    expect($disconnected->trashed())->toBeTrue()
        ->and($disconnected->access_token)->toBeNull()
        ->and($disconnected->refresh_token)->toBeNull()
        ->and($disconnected->token_expires_at)->toBeNull();
});

it('still disconnects when google fails to revoke the grant', function (): void {
    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response(status: 500)]);
    $this->account->update(['provider' => EmailProvider::GMAIL, 'refresh_token' => 'refresh-to-revoke']);

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $this->account->id]);

    $disconnected = ConnectedAccount::withTrashed()->findOrFail($this->account->id);

    expect($disconnected->trashed())->toBeTrue()
        ->and($disconnected->refresh_token)->toBeNull();
});

it('still disconnects when the google revoke times out', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));
    Log::spy();
    $this->account->update(['provider' => EmailProvider::GMAIL, 'refresh_token' => 'refresh-to-revoke']);

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $this->account->id]);

    expect(ConnectedAccount::withTrashed()->findOrFail($this->account->id))
        ->trashed()->toBeTrue()
        ->refresh_token->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ($context['connected_account_id'] ?? null) === $this->account->id)->once();
});

it('keeps the google grant when the same mailbox is still connected in another workspace', function (): void {
    Http::fake();
    $this->account->update(['provider' => EmailProvider::GMAIL, 'provider_account_id' => 'google-user-1', 'refresh_token' => 'shared-refresh']);

    $otherWorkspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $sibling = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->user->id,
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'google-user-1',
        'email_address' => $this->account->email_address,
        'refresh_token' => 'shared-refresh',
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $this->account->id]);

    Http::assertNothingSent();
    expect($sibling->fresh()->refresh_token)->toBe('shared-refresh');
});

it('clears microsoft tokens on disconnect without calling a revoke endpoint', function (): void {
    Http::fake();
    $this->account->update(['provider' => EmailProvider::AZURE, 'refresh_token' => 'graph-refresh']);

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $this->account->id]);

    Http::assertNothingSent();
    expect(ConnectedAccount::withTrashed()->findOrFail($this->account->id)->refresh_token)->toBeNull();
});

it('deletes dependent signatures and refreshes the listing on disconnect', function (): void {
    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response()]);
    $signature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $this->account->id])
        ->assertSet('connectedAccounts', fn ($accounts): bool => $accounts->isEmpty());

    $this->assertSoftDeleted(ConnectedAccount::class, ['id' => $this->account->id]);
    $this->assertDatabaseMissing(EmailSignature::class, ['id' => $signature->id]);
});

it('does not delete another user\'s account on disconnect', function (): void {
    $otherUser = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $otherUser->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $otherAccount->id])
        ->assertNotFound();

    $this->assertNotSoftDeleted(ConnectedAccount::class, [
        'id' => $otherAccount->id,
    ]);
});

it('only loads the authenticated user\'s accounts in the current team on mount', function (): void {
    $otherUser = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $otherUser->id,
    ]));

    $ids = livewire(EmailAccountsPage::class)
        ->get('connectedAccounts')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($this->account->id)
        ->not->toContain($otherAccount->id);
});

it('promotes an account to default and demotes the previous default on setDefault', function (): void {
    $current = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->default()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('setDefault', arguments: ['account_id' => $this->account->id])
        ->assertNotified();

    expect($this->account->fresh()->is_default)->toBeTrue()
        ->and($current->fresh()->is_default)->toBeFalse();
});

it('renders set as default in the account menu when the mailbox is not default', function (): void {
    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.actions.set_default'));
});

it('does not render set as default in the account menu when the mailbox is already default', function (): void {
    $this->account->update(['is_default' => true]);

    livewire(EmailAccountsPage::class)
        ->assertDontSee(__('filament/pages/email-accounts.actions.set_default'));
});

it('hides setDefault for the account that is already default', function (): void {
    $default = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->default()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->assertActionHidden(TestAction::make('setDefault')->arguments(['account_id' => $default->id]))
        ->assertActionVisible(TestAction::make('setDefault')->arguments(['account_id' => $this->account->id]));
});

it('does not expose setDefault for another user\'s account', function (): void {
    $otherUser = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $otherUser->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->assertActionHidden(TestAction::make('setDefault')->arguments(['account_id' => $otherAccount->id]));

    expect($otherAccount->fresh()->is_default)->toBeFalse();
});

it('promotes the remaining account to default when the default is disconnected', function (): void {
    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response()]);
    $default = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->default()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $default->id]);

    $this->assertSoftDeleted(ConnectedAccount::class, ['id' => $default->id]);
    expect($this->account->fresh()->is_default)->toBeTrue();
});

it('lists the default account first', function (): void {
    $default = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->default()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $ids = livewire(EmailAccountsPage::class)
        ->get('connectedAccounts')
        ->pluck('id')
        ->all();

    expect($ids[0])->toBe($default->id);
});

it('queues mailbox history import from the account menu', function (): void {
    Bus::fake();

    $this->account->update(['sync_cursor' => 'mail-cursor']);

    livewire(EmailAccountsPage::class)
        ->callAction('reimportHistory', arguments: ['account_id' => $this->account->id])
        ->assertNotified();

    Bus::assertDispatched(RelinkMailboxHistoryJob::class, fn (RelinkMailboxHistoryJob $job): bool => $job->connectedAccount->is($this->account));
    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($this->account));
    Bus::assertNotDispatched(IncrementalEmailSyncJob::class);
});

it('does not re-import another user\'s account', function (): void {
    $otherUser = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $otherUser->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('reimportHistory', arguments: ['account_id' => $otherAccount->id])
        ->assertNotFound();
});

it('shows mailbox capabilities on each connected account', function (): void {
    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.capabilities.email'));
});

it('warns when a connected mailbox cannot send', function (): void {
    $this->account->update([
        'sync_cursor' => 'done',
        'capabilities' => [
            'email' => true,
            'send' => false,
            'calendar' => false,
        ],
    ]);

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/emails/composer.grant_send.heading', ['email' => $this->account->email_address]).' '.__('filament/emails/composer.grant_send.description'))
        ->assertSee(__('filament/pages/email-accounts.in_sync'));
});

it('shows syncing after reconnecting a live mailbox', function (): void {
    Bus::fake();

    $this->account->update([
        'sync_cursor' => 'mail-cursor',
        'history_import_batch_id' => 'batch-before-reconnect',
        'status' => EmailAccountStatus::ACTIVE,
    ]);

    resolve(ConnectAccountAction::class)->execute(new ConnectAccountData(
        userId: (string) $this->user->getKey(),
        workspaceId: (string) $this->workspace->getKey(),
        provider: $this->account->provider->value,
        emailAddress: $this->account->email_address,
        displayName: $this->account->display_name,
        providerAccountId: $this->account->provider_account_id,
        accessToken: 'new-access',
        refreshToken: 'new-refresh',
        tokenExpiresAt: now()->addHour(),
        hasCalendar: $this->account->hasCalendar(),
        hasSend: $this->account->hasSend(),
    ));

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.importing'));
});

it('does not show the syncing badge during background incremental email sync', function (): void {
    $this->account->update([
        'sync_cursor' => 'done',
        'last_synced_at' => now(),
    ]);

    MailboxSyncTracker::markEmailStarted($this->account);

    livewire(EmailAccountsPage::class)
        ->assertDontSee(__('filament/pages/email-accounts.importing'));
});

it('does not show the syncing badge during background incremental calendar sync', function (): void {
    $this->account->update([
        'sync_cursor' => 'done',
        'calendar_sync_cursor' => 'done',
        'last_synced_at' => now(),
        'capabilities' => ['email' => true, 'calendar' => true],
    ]);

    MailboxSyncTracker::markCalendarStarted($this->account);

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.in_sync'))
        ->assertDontSee(__('filament/pages/email-accounts.importing'));
});

it('shows a sync issue badge when incremental sync could not store mail', function (): void {
    $this->account->update([
        'sync_cursor' => 'mail-cursor',
        'last_error' => '3 email(s) could not be stored during sync.',
    ]);

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.sync_error.badge'))
        ->assertDontSee(__('filament/pages/email-accounts.sync_error.heading'))
        ->assertDontSee(__('filament/pages/email-accounts.in_sync'));
});

it('stays in sync after history import store failures', function (): void {
    $batch = resolve(MailboxHistoryImportService::class)->startBatch($this->account);

    $this->account->update([
        'sync_cursor' => 'history-done',
        'history_import_batch_id' => $batch->id,
    ]);

    DB::table('job_batches')->where('id', $batch->id)->update([
        'total_jobs' => 5,
        'pending_jobs' => 0,
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode(['failed-1', 'failed-2']),
        'finished_at' => now()->getTimestamp(),
    ]);

    livewire(EmailAccountsPage::class)
        ->assertDontSee(__('filament/pages/email-accounts.actions.retry_failed_import.label'))
        ->assertDontSee(__('filament/pages/email-accounts.sync_error.heading'))
        ->assertSee(__('filament/pages/email-accounts.in_sync'));
});

it('does not show a retry control after history import store failures', function (): void {
    $batch = resolve(MailboxHistoryImportService::class)->startBatch($this->account);

    $this->account->update([
        'sync_cursor' => 'history-done',
        'history_import_batch_id' => $batch->id,
        'last_error' => 'This message could not be stored after several tries.',
    ]);

    DB::table('job_batches')->where('id', $batch->id)->update([
        'total_jobs' => 5,
        'pending_jobs' => 0,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['failed-1']),
        'finished_at' => now()->getTimestamp(),
    ]);

    livewire(EmailAccountsPage::class)
        ->assertDontSee(__('filament/pages/email-accounts.actions.retry_failed_import.label'))
        ->assertSee(__('filament/pages/email-accounts.in_sync'));
});

it('shows in sync when the mailbox has no recorded error', function (): void {
    $this->account->update(['sync_cursor' => 'mail-cursor']);

    livewire(EmailAccountsPage::class)
        ->assertDontSee(__('filament/pages/email-accounts.sync_error.heading'))
        ->assertSee(__('filament/pages/email-accounts.in_sync'));
});

it('renders Connect Gmail and hides Connect Microsoft without a Microsoft mailbox client', function (): void {
    config()->set('services.gmail.client_id', 'gmail-client');

    config()->set('services.azure.client_id');

    livewire(EmailAccountsPage::class)
        ->assertActionExists('connectGmail')
        ->assertActionVisible('connectGmail')
        ->assertActionHidden('connectAzure');
});

it('offers Connect Microsoft once a Microsoft mailbox client is configured', function (): void {
    config()->set('services.azure.client_id', 'azure-client');

    livewire(EmailAccountsPage::class)
        ->assertActionVisible('connectAzure')
        ->assertSee(__('filament/pages/email-accounts.actions.connect_azure'));
});

it('keeps email settings out of the sidebar and shows only accounts and templates as per-user tabs', function (): void {
    expect(EmailAccountsPage::shouldRegisterNavigation())->toBeFalse()
        ->and(EmailTemplateResource::shouldRegisterNavigation())->toBeFalse()
        ->and(EmailSignaturesPage::shouldRegisterNavigation())->toBeFalse()
        ->and(EmailAccessRequestsPage::shouldRegisterNavigation())->toBeFalse()
        ->and(UserEmailPrivacyPage::shouldRegisterNavigation())->toBeFalse();

    $this->get(EmailAccountsPage::getUrl(tenant: $this->workspace))
        ->assertSuccessful()
        ->assertSee(__('filament/pages/email-accounts.navigation_label'), false)
        ->assertSee(__('filament/resources/email-template.navigation_label'), false)
        ->assertDontSee(__('filament/pages/email-access-requests.navigation_label'), false)
        ->assertDontSee(__('filament/pages/email-privacy-settings.navigation_label'), false)
        ->assertDontSee(__('filament/pages/user-email-privacy.navigation_label'), false);
});
