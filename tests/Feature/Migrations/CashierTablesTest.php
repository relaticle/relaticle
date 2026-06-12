<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('adds cashier customer columns to teams', function (): void {
    expect(Schema::hasColumns('teams', ['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']))->toBeTrue();
});

it('creates cashier subscription tables keyed by team ulid', function (): void {
    expect(Schema::hasColumns('subscriptions', ['team_id', 'type', 'stripe_id', 'stripe_status', 'stripe_price', 'quantity', 'trial_ends_at', 'ends_at']))->toBeTrue()
        ->and(Schema::hasColumns('subscription_items', ['subscription_id', 'stripe_id', 'stripe_product', 'stripe_price', 'quantity']))->toBeTrue()
        ->and(Schema::getColumnType('subscriptions', 'team_id'))->toContain('char');
});
