<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionItem;

it('adds cashier customer columns to workspaces', function (): void {
    expect(Schema::hasColumns('workspaces', ['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']))->toBeTrue();
});

it('creates cashier subscription tables keyed by workspace ulid', function (): void {
    expect(Schema::hasColumns('subscriptions', ['workspace_id', 'type', 'stripe_id', 'stripe_status', 'stripe_price', 'quantity', 'trial_ends_at', 'ends_at']))->toBeTrue()
        ->and(Schema::hasColumns('subscription_items', ['subscription_id', 'stripe_id', 'stripe_product', 'stripe_price', 'quantity']))->toBeTrue()
        // Not toContain('char'), because 'varchar' contains 'char', so a regression to
        // string('workspace_id') would slip through while mismatching workspaces.id.
        ->and(Schema::getColumnType('subscriptions', 'workspace_id'))->toBe('bpchar')
        ->and(Schema::getColumnType('workspaces', 'id'))->toBe('bpchar');
});

it('carries the meter columns cashier writes on swap and add-on', function (): void {
    expect(Schema::hasColumns('subscription_items', ['meter_id', 'meter_event_name']))->toBeTrue();
});

it('cascades billing rows when a workspace is deleted', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;

    $subscription = Subscription::query()->create([
        'workspace_id' => $workspace->getKey(),
        'type' => 'default',
        'stripe_id' => 'sub_cascade_test',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);

    $subscription->items()->create([
        'stripe_id' => 'si_cascade_test',
        'stripe_product' => 'prod_test',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);

    $workspace->delete();

    expect(Subscription::query()->where('stripe_id', 'sub_cascade_test')->exists())->toBeFalse()
        ->and(SubscriptionItem::query()->where('stripe_id', 'si_cascade_test')->exists())->toBeFalse();
});
