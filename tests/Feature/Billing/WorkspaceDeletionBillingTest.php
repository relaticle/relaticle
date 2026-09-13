<?php

declare(strict_types=1);

use App\Actions\Billing\CancelWorkspaceSubscription;
use App\Models\User;
use App\Models\Workspace;

mutates(CancelWorkspaceSubscription::class);

it('does nothing when the workspace has no subscription', function (): void {
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;

    app(CancelWorkspaceSubscription::class)->execute($workspace);
})->throwsNoExceptions();

it('does nothing when the subscription already ended', function (): void {
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_done',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subDay(),
    ]);

    app(CancelWorkspaceSubscription::class)->execute($workspace, immediately: true);
})->throwsNoExceptions();

it('logs instead of throwing when stripe is unreachable for a live subscription', function (): void {
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $workspace->forceFill(['stripe_id' => 'cus_x'])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_live',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    // Tests have no real STRIPE_SECRET, so the Cashier cancel call throws. The action logs and returns.
    app(CancelWorkspaceSubscription::class)->execute($workspace);

    expect($workspace->fresh()->subscription()->stripe_status)->toBe('active');
});
