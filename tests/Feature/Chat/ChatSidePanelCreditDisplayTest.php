<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(ChatSidePanel::class);

it('renders the plan label for the current user', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->assertSee('Free');
});

it('renders the credit balance for the current user', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => 247,
        'credits_used' => 53,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->actingAs($user);
    Filament::setTenant($team);

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->assertSee('247')
        ->assertSee('300');
});

it('shows the Upgrade link for Free users', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->assertSee('Upgrade to Pro');
});

it('does not show the Upgrade link for Pro users', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->currentTeam->plan = Plan::Pro;
    $user->currentTeam->save();

    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->assertDontSee('Upgrade to Pro');
});

it('shows the Pro plan label and Pro allowance', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->plan = Plan::Pro;
    $team->save();

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => Plan::Pro->credits(),
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->actingAs($user);
    Filament::setTenant($team);

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->assertSee('Pro')
        ->assertSee(number_format(Plan::Pro->credits()));
});
