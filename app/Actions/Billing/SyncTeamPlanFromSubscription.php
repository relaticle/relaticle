<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Services\CreditService;

final readonly class SyncTeamPlanFromSubscription
{
    /**
     * Stripe statuses a subscription can hold without ever having granted
     * access — a checkout whose first payment failed or was abandoned.
     *
     * @var list<string>
     */
    private const array NON_GRANTING_STATUSES = ['incomplete', 'incomplete_expired'];

    public function __construct(private CreditService $credits) {}

    public function execute(Team $team, Subscription $subscription): void
    {
        $subscriptionPlan = $this->planForPrice($subscription->stripe_price);

        if (! $subscriptionPlan instanceof Plan) {
            Log::warning('Stripe subscription price is not mapped to a plan', [
                'team_id' => $team->getKey(),
                'subscription_id' => $subscription->stripe_id,
                'stripe_price' => $subscription->stripe_price,
            ]);

            return;
        }

        $target = $this->targetPlan($team, $subscription, $subscriptionPlan);

        if (! $target instanceof Plan) {
            return;
        }

        if ($team->plan === $target) {
            return;
        }

        DB::transaction(function () use ($team, $target, $subscription): void {
            $team->plan = $target;
            $team->save();

            $this->credits->resetPeriod($team);

            Log::info('Team plan synced from Stripe subscription', [
                'team_id' => $team->getKey(),
                'plan' => $target->value,
                'subscription_id' => $subscription->stripe_id,
            ]);
        });
    }

    private function targetPlan(Team $team, Subscription $subscription, Plan $subscriptionPlan): ?Plan
    {
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
        if ($team->onGenericTrial()) {
            return null;
        }

        // Only downgrade a plan this subscription granted — a sysadmin-assigned
        // plan (e.g. Enterprise) must survive an unrelated subscription ending.
        if ($team->plan === $subscriptionPlan) {
            return Plan::default();
        }

        return null;
    }

    private function planForPrice(?string $priceId): ?Plan
    {
        if ($priceId === null) {
            return null;
        }

        /** @var array<string, string|null> $prices */
        $prices = config('services.stripe.prices', []);

        foreach ($prices as $key => $mappedPriceId) {
            if ($mappedPriceId !== null && $mappedPriceId === $priceId) {
                return Plan::tryFrom(explode('_', $key)[0]);
            }
        }

        return null;
    }
}
