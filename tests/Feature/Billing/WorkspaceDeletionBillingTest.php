<?php

declare(strict_types=1);

use App\Actions\Billing\CancelWorkspaceSubscription;
use App\Actions\Jetstream\DeleteWorkspace;
use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Tests\Helpers\StripeRecorder;

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

it('cancels managed subscriptions and voids their open invoices when the workspace is deleted', function (): void {
    config()->set('cashier.secret', 'sk_test_fake');

    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $workspace->forceFill(['stripe_id' => 'cus_deleted', 'plan' => Plan::Concierge])->save();

    foreach (['managed' => 'sub_paid_managed', 'managed_pending' => 'sub_pending_managed'] as $type => $stripeId) {
        $workspace->subscriptions()->create([
            'type' => $type,
            'stripe_id' => $stripeId,
            'stripe_status' => 'active',
            'stripe_price' => "price_inline_{$stripeId}",
            'quantity' => 1,
        ]);
    }

    $stripe = StripeRecorder::install()
        ->respondTo('GET /v1/invoices', ['object' => 'list', 'has_more' => false, 'data' => [['id' => 'in_open', 'object' => 'invoice', 'status' => 'open']]])
        ->respondTo('/v1/subscriptions/', ['id' => 'sub_x', 'object' => 'subscription', 'status' => 'canceled']);

    app(DeleteWorkspace::class)->delete($workspace);

    StripeRecorder::uninstall();

    expect($stripe->countFor('DELETE /v1/subscriptions/sub_paid_managed'))->toBe(1)
        ->and($stripe->countFor('DELETE /v1/subscriptions/sub_pending_managed'))->toBe(1)
        ->and($stripe->countFor('POST /v1/invoices/in_open/void'))->toBe(2);
});
