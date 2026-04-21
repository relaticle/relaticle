<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(EmailAccountsPage::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('redirects to oauth when enabling calendar sync', function (): void {
    Bus::fake([InitialCalendarSyncJob::class]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'capabilities' => ['email' => true, 'calendar' => false],
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('syncCalendar', arguments: ['account_id' => $account->id])
        ->assertRedirect(route('email-accounts.redirect', ['provider' => 'gmail']).'?capability=calendar');

    expect($account->fresh()?->hasCalendar())->toBeFalse();
    Bus::assertNotDispatched(InitialCalendarSyncJob::class);
});

it('disables calendar sync when already enabled', function (): void {
    Bus::fake([InitialCalendarSyncJob::class]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('syncCalendar', arguments: ['account_id' => $account->id]);

    expect($account->fresh()?->hasCalendar())->toBeFalse();
    Bus::assertNotDispatched(InitialCalendarSyncJob::class);
});
