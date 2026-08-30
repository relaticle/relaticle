<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

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

it('prompts to configure a mailbox when no account is connected', function (): void {
    $this->account->forceDelete();

    livewire(EmailInboxPage::class)
        ->assertSee(__('filament/pages/email-accounts.not_connected.inbox.heading'))
        ->assertSee(__('filament/pages/email-accounts.not_connected.action'))
        ->assertSeeHtml(EmailAccountsPage::getUrl());
});

it('shows the inbox instead of the prompt once an account is connected', function (): void {
    livewire(EmailInboxPage::class)
        ->assertDontSee(__('filament/pages/email-accounts.not_connected.inbox.heading'))
        ->assertActionDoesNotExist('composeEmail');
});
