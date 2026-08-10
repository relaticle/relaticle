<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionItem;

it('adds cashier customer columns to teams', function (): void {
    expect(Schema::hasColumns('teams', ['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']))->toBeTrue();
});

it('creates cashier subscription tables keyed by team ulid', function (): void {
    expect(Schema::hasColumns('subscriptions', ['team_id', 'type', 'stripe_id', 'stripe_status', 'stripe_price', 'quantity', 'trial_ends_at', 'ends_at']))->toBeTrue()
        ->and(Schema::hasColumns('subscription_items', ['subscription_id', 'stripe_id', 'stripe_product', 'stripe_price', 'quantity']))->toBeTrue()
        // Not toContain('char') — 'varchar' contains 'char', so a regression to
        // string('team_id') would slip through while mismatching teams.id.
        ->and(Schema::getColumnType('subscriptions', 'team_id'))->toBe('bpchar')
        ->and(Schema::getColumnType('teams', 'id'))->toBe('bpchar');
});

it('carries the meter columns cashier writes on swap and add-on', function (): void {
    expect(Schema::hasColumns('subscription_items', ['meter_id', 'meter_event_name']))->toBeTrue();
});

it('cascades billing rows when a workspace is deleted', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;

    $subscription = Subscription::query()->create([
        'team_id' => $team->getKey(),
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

    $team->delete();

    expect(Subscription::query()->where('stripe_id', 'sub_cascade_test')->exists())->toBeFalse()
        ->and(SubscriptionItem::query()->where('stripe_id', 'si_cascade_test')->exists())->toBeFalse();
});
