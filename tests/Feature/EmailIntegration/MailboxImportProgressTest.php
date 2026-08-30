<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(EmailAccountsPage::class);

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
        ->assertSee(__('filament/pages/email-accounts.importing_progress', [
            'imported' => number_format(12),
            'estimated' => number_format(40),
        ]));
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
        ->assertDontSee(__('filament/pages/email-accounts.importing_count', ['count' => '0']));
});
