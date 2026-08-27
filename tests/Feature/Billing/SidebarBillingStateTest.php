<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Models\User;
use App\Services\Billing\SidebarBillingState;
use Laravel\Pennant\Feature;

mutates(SidebarBillingState::class);

beforeEach(function (): void {
    Feature::define(BillingFeature::class, true);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
});

it('counts the days left on an active trial', function (): void {
    $this->team->forceFill(['trial_ends_at' => now()->addDays(9)->addHour()])->save();

    $state = resolve(SidebarBillingState::class)->for($this->team->fresh());

    expect($state)->not->toBeNull()
        ->and($state['label'])->toBe('10 days left on trial!')
        ->and($state['action'])->toBe(__('billing.sidebar.keep_pro'));
});

it('says one day, singular, on the final day', function (): void {
    $this->team->forceFill(['trial_ends_at' => now()->addHours(6)])->save();

    expect(resolve(SidebarBillingState::class)->for($this->team->fresh())['label'])
        ->toBe('1 day left on trial!');
});

it('asks a paused workspace to subscribe', function (): void {
    $this->team->forceFill([
        'trial_ends_at' => now()->subDay(),
        'hosted_free_grandfathered_at' => null,
    ])->save();

    $state = resolve(SidebarBillingState::class)->for($this->team->fresh());

    expect($state)->not->toBeNull()
        ->and($state['label'])->toBe(__('billing.sidebar.paused'))
        ->and($state['action'])->toBe(__('billing.sidebar.subscribe'));
});

it('asks nothing of a grandfathered free workspace', function (): void {
    $this->team->forceFill([
        'trial_ends_at' => null,
        'plan' => Plan::Free,
        'hosted_free_grandfathered_at' => now()->subMonth(),
    ])->save();

    expect(resolve(SidebarBillingState::class)->for($this->team->fresh()))->toBeNull();
});

it('asks nothing of an enterprise workspace', function (): void {
    $this->team->forceFill(['trial_ends_at' => null, 'plan' => Plan::Enterprise])->save();

    expect(resolve(SidebarBillingState::class)->for($this->team->fresh()))->toBeNull();
});

it('stays silent when billing is switched off entirely', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->team->forceFill(['trial_ends_at' => now()->addDays(3)])->save();

    expect(resolve(SidebarBillingState::class)->for($this->team->fresh()))->toBeNull();
});
