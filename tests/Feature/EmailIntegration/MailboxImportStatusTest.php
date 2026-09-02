<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\PeopleResource;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Livewire\MailboxImportStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(Dashboard::class, EmailAccountsPage::class, MailboxImportStatus::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->team);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function importingAccount(array $overrides = []): ConnectedAccount
{
    /** @var User $user */
    $user = test()->user;

    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $user->currentTeam->getKey(),
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
    $other = User::factory()->withTeam()->create();

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $other->currentTeam->getKey(),
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

it('keeps a completed import visible until dismiss on this instance', function (): void {
    $account = importingAccount();

    $component = livewire(MailboxImportStatus::class)
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_syncing'));

    $account->update(['sync_cursor' => 'history-1', 'last_synced_at' => now()]);

    $component->call('refreshStatus')
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_complete'))
        ->assertSee(trans_choice('filament/pages/email-accounts.sync_status.emails_processed', 643, ['count' => 643]));
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
        'team_id' => test()->team->getKey(),
        'user_id' => test()->user->getKey(),
        'sync_cursor' => 'done',
        'last_synced_at' => now(),
    ]));

    livewire(MailboxImportStatus::class, ['placement' => 'home'])
        ->assertDontSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 0]))
        ->assertDontSee(__('filament/pages/email-accounts.sync_status.title_syncing'));
});

it('shows the home sync section on the dashboard page', function (): void {
    $account = importingAccount(['initial_sync_imported' => 57, 'initial_sync_estimated' => 100]);

    livewire(Dashboard::class)
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_syncing'))
        ->assertSee($account->email_address)
        ->assertSee(trans_choice('filament/pages/email-accounts.sync_status.emails_processed', 57, ['count' => 57]))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 57]))
        ->assertSee('role="progressbar"', false);
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
