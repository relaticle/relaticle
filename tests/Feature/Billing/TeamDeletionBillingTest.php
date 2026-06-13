<?php

declare(strict_types=1);

use App\Actions\Billing\CancelTeamSubscription;
use App\Models\Team;
use App\Models\User;

mutates(CancelTeamSubscription::class);

it('does nothing when the team has no subscription', function (): void {
    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;

    app(CancelTeamSubscription::class)->execute($team);
})->throwsNoExceptions();

it('does nothing when the subscription already ended', function (): void {
    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_done',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subDay(),
    ]);

    app(CancelTeamSubscription::class)->execute($team, immediately: true);
})->throwsNoExceptions();

it('logs instead of throwing when stripe is unreachable for a live subscription', function (): void {
    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_x'])->save();
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_live',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    // No real STRIPE_SECRET in tests → the Cashier cancel call throws → action logs and returns.
    app(CancelTeamSubscription::class)->execute($team);

    expect($team->fresh()->subscription()->stripe_status)->toBe('active');
})->throwsNoExceptions();
