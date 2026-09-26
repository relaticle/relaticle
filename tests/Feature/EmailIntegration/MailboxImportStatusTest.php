<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\PeopleResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Livewire\MailboxImportStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

mutates(Dashboard::class, EmailAccountsPage::class, MailboxImportStatus::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->workspace);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function importingAccount(array $overrides = []): ConnectedAccount
{
    /** @var User $user */
    $user = test()->user;

    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => null,
        'initial_sync_imported' => 643,
        'initial_sync_estimated' => 1128,
        ...$overrides,
    ]));
}

it('shows processed count and a progress bar while history is importing', function (): void {
    $account = importingAccount();

    livewire(MailboxImportStatus::class)
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_syncing'))
        ->assertSee($account->email_address)
        ->assertSee(trans_choice('filament/pages/email-accounts.sync_status.emails_processed', 643, ['count' => 643]))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 57]))
        ->assertSee('role="progressbar"', false)
        ->assertDontSee(__('filament/pages/email-accounts.sync_status.title_complete'));
});

it('hides a mailbox belonging to another user', function (): void {
    $other = User::factory()->withWorkspace()->create();

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $other->currentWorkspace->getKey(),
        'user_id' => $other->getKey(),
        'sync_cursor' => null,
        'initial_sync_imported' => 10,
        'initial_sync_estimated' => 20,
    ]));

    livewire(MailboxImportStatus::class)
        ->assertDontSee(__('filament/pages/email-accounts.sync_status.title_syncing'));
});

it('hides a failed mailbox from the section', function (): void {
    importingAccount(['status' => EmailAccountStatus::ERROR]);

    livewire(MailboxImportStatus::class)
        ->assertDontSee(__('filament/pages/email-accounts.sync_status.title_syncing'));
});

it('marks import complete on home when store jobs failed but listing finished', function (): void {
    $account = importingAccount([
        'capabilities' => ['email' => true, 'calendar' => false],
    ]);

    $component = livewire(MailboxImportStatus::class, ['placement' => 'home'])
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_syncing'));

    $batchId = attachHistoryImportBatch($account);

    $account->update(['sync_cursor' => 'history-done', 'last_synced_at' => now()]);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 0,
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode(['failed-1', 'failed-2']),
        'finished_at' => now()->getTimestamp(),
    ]);

    expect($account->fresh()?->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and($account->fresh()?->showsHomeMailboxImportProgress())->toBeFalse();

    $component->call('refreshStatus')
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_complete'))
        ->assertDontSee(__('filament/pages/email-accounts.sync_status.title_syncing'));
});

it('keeps a completed import visible until dismiss on this instance', function (): void {
    $account = importingAccount();

    $component = livewire(MailboxImportStatus::class)
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_syncing'));

    $account->update(['sync_cursor' => 'history-1', 'last_synced_at' => now()]);

    $component->call('refreshStatus')
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_complete'))
        ->assertSee('data-mailbox-import-complete-icon', false)
        ->assertSee('text-success-600', false)
        ->assertSee(trans_choice('filament/pages/email-accounts.sync_status.emails_processed', 643, ['count' => 643]));
});

it('does not show the home import section during calendar-only incremental sync', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($user->currentWorkspace);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => 'done',
        'calendar_sync_cursor' => 'done',
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    MailboxSyncTracker::markCalendarStarted($account);

    expect(livewire(MailboxImportStatus::class, ['placement' => 'home'])->instance()->shouldRender())->toBeFalse();
});

it('shows import complete while incremental sync runs after history finishes', function (): void {
    $account = importingAccount();

    $component = livewire(MailboxImportStatus::class)
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_syncing'));

    $account->update(['sync_cursor' => 'history-1', 'last_synced_at' => now()]);

    MailboxSyncTracker::markCalendarStarted($account);

    $component->call('refreshStatus')
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_complete'))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 100]))
        ->assertSee('data-mailbox-import-complete-icon', false);
});

it('hides the section after dismiss', function (): void {
    $account = importingAccount();

    livewire(MailboxImportStatus::class)
        ->call('dismiss', $account->getKey())
        ->assertDontSee($account->email_address)
        ->assertDontSee(__('filament/pages/email-accounts.sync_status.title_syncing'));
});

it('stacks two importing mailboxes in the section', function (): void {
    $first = importingAccount(['email_address' => 'maya@example.com']);
    $second = importingAccount([
        'email_address' => 'alex@example.com',
        'initial_sync_imported' => 412,
        'initial_sync_estimated' => 2000,
    ]);

    livewire(MailboxImportStatus::class)
        ->assertSee($first->email_address)
        ->assertSee($second->email_address);
});

it('renders each importing mailbox in the home section', function (): void {
    importingAccount(['initial_sync_imported' => 50, 'initial_sync_estimated' => 100]);
    importingAccount([
        'email_address' => 'alex@example.com',
        'initial_sync_imported' => 10,
        'initial_sync_estimated' => 100,
    ]);

    livewire(MailboxImportStatus::class, ['placement' => 'home'])
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_syncing'))
        ->assertSee(trans_choice('filament/pages/email-accounts.sync_status.emails_processed', 50, ['count' => 50]))
        ->assertSee(trans_choice('filament/pages/email-accounts.sync_status.emails_processed', 10, ['count' => 10]))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 50]))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 10]))
        ->assertSee('role="progressbar"', false)
        ->assertDontSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 30]));
});

it('renders nothing on home when nothing is importing', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'user_id' => test()->user->getKey(),
        'sync_cursor' => 'done',
        'last_synced_at' => now(),
    ]));

    livewire(MailboxImportStatus::class, ['placement' => 'home'])
        ->assertDontSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 0]))
        ->assertDontSee(__('filament/pages/email-accounts.sync_status.title_syncing'));
});

it('does not list meetings on a mailbox without calendar', function (): void {
    importingAccount();

    livewire(MailboxImportStatus::class, ['placement' => 'home'])
        ->assertSee(trans_choice('filament/pages/email-accounts.sync_status.emails_processed', 643, ['count' => 643]))
        ->assertDontSee(trans_choice('filament/pages/email-accounts.sync_status.meetings_processed', 0, ['count' => 0]));
});

it('lists meetings processed above emails on home when calendar is enabled', function (): void {
    importingAccount([
        'capabilities' => ['email' => true, 'calendar' => true],
        'initial_calendar_sync_imported' => 12,
        'initial_sync_imported' => 57,
        'initial_sync_estimated' => 100,
    ]);

    $html = livewire(MailboxImportStatus::class, ['placement' => 'home'])->html();
    $meetings = trans_choice('filament/pages/email-accounts.sync_status.meetings_processed', 12, ['count' => 12]);
    $emails = trans_choice('filament/pages/email-accounts.sync_status.emails_processed', 57, ['count' => 57]);

    expect($html)->toContain($meetings)->toContain($emails);

    $meetingsPosition = strpos($html, $meetings);
    $emailsPosition = strpos($html, $emails);

    expect($meetingsPosition)->toBeInt()
        ->and($emailsPosition)->toBeInt()
        ->and($meetingsPosition)->toBeLessThan($emailsPosition);
});

it('shows mailbox sync in the meetings section on the dashboard page', function (): void {
    importingAccount(['initial_sync_imported' => 57, 'initial_sync_estimated' => 100]);

    livewire(Dashboard::class)
        ->assertSee('data-testid="meetings-mailbox-sync"', escape: false)
        ->assertSee(__('filament/pages/dashboard.meetings.syncing.title_with_percent', ['percent' => 57]))
        ->assertDontSee('data-mailbox-import="home"', false);
});

it('shows the syncing badge on the accounts page without the progress section', function (): void {
    $account = importingAccount(['initial_sync_imported' => 57, 'initial_sync_estimated' => 100]);

    livewire(EmailAccountsPage::class)
        ->assertSee($account->email_address)
        ->assertSee(__('filament/pages/email-accounts.importing'))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 57]))
        ->assertDontSee(trans_choice('filament/pages/email-accounts.sync_status.emails_processed', 57, ['count' => 57]))
        ->assertDontSee('data-mailbox-import="accounts"', false);
});

it('does not show a floating import card on people or companies', function (): void {
    $account = importingAccount(['email_address' => 'sync-status@example.com']);

    $this->get(PeopleResource::getUrl('index'))
        ->assertOk()
        ->assertDontSee('data-mailbox-import="overlay"', escape: false)
        ->assertDontSee('data-mailbox-import="home"', escape: false)
        ->assertDontSee('data-mailbox-import="accounts"', escape: false)
        ->assertDontSee($account->email_address);

    $this->get(CompanyResource::getUrl('index'))
        ->assertOk()
        ->assertDontSee('data-mailbox-import="overlay"', escape: false)
        ->assertDontSee($account->email_address);
});
