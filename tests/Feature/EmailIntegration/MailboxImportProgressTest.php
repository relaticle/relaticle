<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(EmailAccountsPage::class, ConnectedAccount::class);

it('shows import progress while the mailbox cursor has not been written', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => null,
        'initial_sync_imported' => 12,
        'initial_sync_estimated' => 40,
    ]));

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.importing'))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 30]))
        ->assertSee('role="progressbar"', false)
        ->assertSee('aria-valuenow="12"', false)
        ->assertSee('aria-valuemax="40"', false)
        ->assertSee('scaleX(0.3)', false);
});

it('shows in sync after the mailbox cursor is written', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => 'history-1',
        'last_synced_at' => now(),
    ]));

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.in_sync'))
        ->assertDontSee(__('filament/pages/email-accounts.importing'));
});

it('picks up a new imported count when the accounts list refreshes', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => null,
        'initial_sync_imported' => 0,
        'initial_sync_estimated' => 387,
    ]));

    $page = livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 0]));

    $account->update(['initial_sync_imported' => 24]);

    $page->call('refreshAccounts')
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 6]))
        ->assertSee('aria-valuenow="24"', false);
});

it('shows an indeterminate import bar when the mailbox size is unknown', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => null,
        'initial_sync_imported' => 8,
        'initial_sync_estimated' => null,
    ]));

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.importing'))
        ->assertDontSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 8]))
        ->assertSee('role="progressbar"', false)
        ->assertDontSee('aria-valuenow', false);
});
