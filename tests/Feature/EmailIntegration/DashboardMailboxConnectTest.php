<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Filament\Concerns\HasConnectMailboxActions;
use Relaticle\EmailIntegration\Livewire\MailboxConnectPrompt;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(Dashboard::class, MailboxConnectPrompt::class, HasConnectMailboxActions::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->team);
});

it('shows the Google connect prompt on home when no mailbox is connected', function (): void {
    livewire(MailboxConnectPrompt::class)
        ->assertSee(__('filament/pages/dashboard.mailbox.empty.title'))
        ->assertSee(__('filament/pages/dashboard.mailbox.empty.description'))
        ->assertSee(__('filament/pages/email-accounts.actions.connect_gmail'))
        ->assertActionVisible('connectGmail')
        ->assertActionHidden('connectAzure');
});

it('links Connect Google Account to the Gmail OAuth redirect', function (): void {
    livewire(MailboxConnectPrompt::class)
        ->assertActionHasUrl(
            TestAction::make('connectGmail'),
            route('email-accounts.redirect', ['provider' => 'gmail']),
        );
});

it('hides the prompt once a mailbox is connected', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    livewire(MailboxConnectPrompt::class)
        ->assertDontSee(__('filament/pages/dashboard.mailbox.empty.title'))
        ->assertDontSee(__('filament/pages/email-accounts.actions.connect_gmail'));
});

it('still shows the prompt when the only mailbox is disconnected', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->disconnected()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    livewire(MailboxConnectPrompt::class)
        ->assertSee(__('filament/pages/dashboard.mailbox.empty.title'))
        ->assertActionVisible('connectGmail');
});

it('does not treat a teammate mailbox as connected for this user', function (): void {
    $teammate = User::factory()->create(['current_team_id' => $this->team->id]);

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
    ]));

    livewire(MailboxConnectPrompt::class)
        ->assertSee(__('filament/pages/dashboard.mailbox.empty.title'));
});

it('hides the prompt when email integration is off', function (): void {
    Feature::define(EmailIntegration::class, false);

    livewire(MailboxConnectPrompt::class)
        ->assertDontSee(__('filament/pages/dashboard.mailbox.empty.title'))
        ->assertDontSee(__('filament/pages/email-accounts.actions.connect_gmail'));
});

it('shows the connect prompt on the dashboard page', function (): void {
    livewire(Dashboard::class)
        ->assertSee(__('filament/pages/dashboard.mailbox.empty.title'))
        ->assertSee(__('filament/pages/email-accounts.actions.connect_gmail'))
        ->assertSee('data-mailbox-connect="home"', escape: false);
});

it('does not show the connect prompt on the dashboard once a mailbox is connected', function (): void {
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    livewire(Dashboard::class)
        ->assertDontSee(__('filament/pages/dashboard.mailbox.empty.title'))
        ->assertDontSee('data-mailbox-connect="home"', escape: false);
});
