<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Models\User;
use Laravel\Cashier\Subscription;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Billing\SidebarBillingState;

mutates(SidebarBillingState::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
});

it('counts the days left on an active trial', function (): void {
    withHostedBilling();

    $this->workspace->forceFill(['trial_ends_at' => now()->addDays(9)->addHour()])->save();

    $state = resolve(SidebarBillingState::class)->for($this->workspace->fresh());

    expect($state)->not->toBeNull()
        ->and($state['label'])->toBe('10 days left on trial!')
        ->and($state['action'])->toBe(__('cloud::billing.sidebar.keep_pro'))
        ->and($state['urgent'])->toBeFalse();
});

it('says one day, singular, on the final day', function (): void {
    withHostedBilling();

    $this->workspace->forceFill(['trial_ends_at' => now()->addHours(6)])->save();

    expect(resolve(SidebarBillingState::class)->for($this->workspace->fresh())['label'])
        ->toBe('1 day left on trial!');
});

it('asks a paused workspace to subscribe', function (): void {
    withHostedBilling();

    $this->workspace->forceFill([
        'trial_ends_at' => now()->subDay(),
        'hosted_free_grandfathered_at' => null,
    ])->save();

    $state = resolve(SidebarBillingState::class)->for($this->workspace->fresh());

    expect($state)->not->toBeNull()
        ->and($state['label'])->toBe(__('cloud::billing.sidebar.paused'))
        ->and($state['action'])->toBe(__('cloud::billing.sidebar.subscribe'));
});

it('asks a workspace whose subscription ended to subscribe', function (): void {
    withHostedBilling();

    Subscription::factory()->canceled()->create(['workspace_id' => $this->workspace->getKey()]);

    $state = resolve(SidebarBillingState::class)->for($this->workspace->fresh());

    expect($state)->not->toBeNull()
        ->and($state['label'])->toBe(__('cloud::billing.sidebar.paused'))
        ->and($state['action'])->toBe(__('cloud::billing.sidebar.subscribe'))
        ->and($state['urgent'])->toBeFalse();
});

it('flags a past-due workspace even though its subscription still reads valid', function (): void {
    withHostedBilling();

    $this->workspace->forceFill(['plan' => Plan::Pro])->save();
    $this->workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_sidebar_past_due',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    $state = resolve(SidebarBillingState::class)->for($this->workspace->fresh());

    expect($state)->not->toBeNull()
        ->and($state['label'])->toBe(__('cloud::billing.sidebar.past_due'))
        ->and($state['action'])->toBe(__('cloud::billing.sidebar.fix'))
        ->and($state['urgent'])->toBeTrue();
});

it('asks a workspace whose past-due subscription has ended to subscribe, not to fix a card', function (): void {
    withHostedBilling();

    $this->workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_sidebar_past_due_ended',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subDay(),
    ]);

    $state = resolve(SidebarBillingState::class)->for($this->workspace->fresh());

    expect($state)->not->toBeNull()
        ->and($state['label'])->toBe(__('cloud::billing.sidebar.paused'))
        ->and($state['urgent'])->toBeFalse();
});

it('asks nothing of a paying subscriber', function (): void {
    withHostedBilling();

    $this->workspace->forceFill(['plan' => Plan::Pro])->save();
    $this->workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_sidebar_active',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    expect(resolve(SidebarBillingState::class)->for($this->workspace->fresh()))->toBeNull();
});

it('asks nothing of a grandfathered free workspace', function (): void {
    withHostedBilling();

    $this->workspace->forceFill([
        'trial_ends_at' => null,
        'plan' => Plan::Free,
        'hosted_free_grandfathered_at' => now()->subMonth(),
    ])->save();

    expect(resolve(SidebarBillingState::class)->for($this->workspace->fresh()))->toBeNull();
});

it('asks nothing of an enterprise workspace', function (): void {
    withHostedBilling();

    $this->workspace->forceFill(['trial_ends_at' => null, 'plan' => Plan::Enterprise])->save();

    expect(resolve(SidebarBillingState::class)->for($this->workspace->fresh()))->toBeNull();
});

it('stays silent when billing is switched off entirely', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->workspace->forceFill(['trial_ends_at' => now()->addDays(3)])->save();

    expect(resolve(SidebarBillingState::class)->for($this->workspace->fresh()))->toBeNull();
});
