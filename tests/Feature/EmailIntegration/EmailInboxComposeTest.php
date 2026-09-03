<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Livewire\DraftsTable;
use Relaticle\EmailIntegration\Livewire\OutboxTable;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(EmailInboxPage::class, DraftsTable::class, OutboxTable::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'email_address' => 'sender@example.com',
        'display_name' => 'Test Sender',
    ]));
});

it('shows the page tabs and configure empty state when no account is connected', function (): void {
    $this->account->forceDelete();

    livewire(EmailInboxPage::class)
        ->assertSee(__('filament/pages/email-inbox.tabs.drafts'))
        ->assertSee(__('filament/pages/email-inbox.tabs.outbox'))
        ->assertSee(__('filament/pages/email-inbox.tabs.failed'))
        ->assertSee(__('filament/pages/email-inbox.tabs.templates'))
        ->assertSee(__('filament/pages/email-inbox.tabs.requests'))
        ->assertSee(__('filament/pages/email-accounts.not_connected.inbox.heading'))
        ->assertSee(__('filament/pages/email-accounts.not_connected.action'))
        ->assertSeeHtml(EmailAccountsPage::getUrl());
});

it('shows the inbox instead of the prompt once an account is connected', function (): void {
    livewire(EmailInboxPage::class)
        ->assertDontSee(__('filament/pages/email-accounts.not_connected.inbox.heading'));
});

it('does not expose compose as a page header action', function (): void {
    livewire(EmailInboxPage::class)
        ->assertActionDoesNotExist('composeEmail');
});

it('opens the floating composer from the drafts table header', function (): void {
    livewire(DraftsTable::class)
        ->assertTableHeaderActionsExistInOrder(['composeEmail'])
        ->callAction(TestAction::make('composeEmail')->table())
        ->assertDispatched('composer:open');
});

it('does not put compose on the outbox table header', function (): void {
    expect(livewire(OutboxTable::class)->instance()->getTable()->getHeaderActions())
        ->toBeEmpty();
});

it('hides the compose action when no account is connected', function (): void {
    $this->account->forceDelete();

    livewire(DraftsTable::class)
        ->assertDontSee(__('filament/concerns/email-compose.actions.compose.label'));
});

it('keeps the compose action when the mailbox cannot send', function (): void {
    $this->account->update([
        'capabilities' => [
            'email' => true,
            'send' => false,
            'calendar' => false,
        ],
    ]);

    livewire(DraftsTable::class)
        ->assertSee(__('filament/concerns/email-compose.actions.compose.label'))
        ->callAction(TestAction::make('composeEmail')->table())
        ->assertDispatched('composer:open')
        ->assertDontSee(__('filament/pages/email-accounts.not_connected.inbox.heading'));
});

it('keeps compose and the drafts empty copy when the mailbox has a sync error', function (): void {
    $this->account->update([
        'status' => EmailAccountStatus::ERROR,
        'capabilities' => [
            'email' => true,
            'send' => false,
            'calendar' => false,
        ],
    ]);

    livewire(DraftsTable::class)
        ->assertSee(__('filament/concerns/email-compose.actions.compose.label'))
        ->assertSee(__('filament/pages/email-inbox.drafts.empty.heading'))
        ->assertDontSee(__('filament/pages/email-accounts.not_connected.inbox.heading'));
});

it('hides the compose action when the mailbox is disconnected', function (): void {
    $this->account->update([
        'status' => EmailAccountStatus::DISCONNECTED,
    ]);

    livewire(DraftsTable::class)
        ->assertDontSee(__('filament/concerns/email-compose.actions.compose.label'))
        ->assertSee(__('filament/pages/email-accounts.not_connected.inbox.heading'));
});
