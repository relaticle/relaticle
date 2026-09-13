<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Services\CreditService;

final readonly class SyncWorkspacePlanFromSubscription
{
    /**
     * Stripe statuses a subscription can hold without ever having granted
     * access: a checkout whose first payment failed or was abandoned.
     *
     * @var list<string>
     */
    private const array NON_GRANTING_STATUSES = ['incomplete', 'incomplete_expired'];

    public function __construct(private CreditService $credits) {}

    public function execute(Workspace $workspace, Subscription $subscription): void
    {
        $subscriptionPlan = Plan::fromStripePrice($subscription->stripe_price);

        if (! $subscriptionPlan instanceof Plan) {
            Log::warning('Stripe subscription price is not mapped to a plan', [
                'workspace_id' => $workspace->getKey(),
                'subscription_id' => $subscription->stripe_id,
                'stripe_price' => $subscription->stripe_price,
            ]);

            return;
        }

        $target = $this->targetPlan($workspace, $subscription, $subscriptionPlan);

        if (! $target instanceof Plan) {
            return;
        }

        if ($workspace->plan === $target) {
            return;
        }

        DB::transaction(function () use ($workspace, $target, $subscription): void {
            $workspace->plan = $target;
            $workspace->save();

            $this->credits->resetPeriod($workspace);

            Log::info('Workspace plan synced from Stripe subscription', [
                'workspace_id' => $workspace->getKey(),
                'plan' => $target->value,
                'subscription_id' => $subscription->stripe_id,
            ]);
        });
    }

    private function targetPlan(Workspace $workspace, Subscription $subscription, Plan $subscriptionPlan): ?Plan
    {
        if ($workspace->plan === Plan::Enterprise && $subscriptionPlan !== Plan::Enterprise) {
            return null;
        }

        if ($subscription->valid()) {
            return $subscriptionPlan;
        }

        // A subscription that never charged (abandoned or failed checkout) has
        // granted nothing, so it must not take anything away either.
        if (in_array($subscription->stripe_status, self::NON_GRANTING_STATUSES, true)) {
            return null;
        }

        // A running trial grants the same plan a subscription would, so plan
        // equality alone cannot prove this subscription is what granted it.
        if ($workspace->onGenericTrial()) {
            return null;
        }

        // Only downgrade a plan this subscription granted. A sysadmin-assigned
        // plan (e.g. Enterprise) must survive an unrelated subscription ending.
        if ($workspace->plan === $subscriptionPlan) {
            return Plan::default();
        }

        return null;
    }
}
