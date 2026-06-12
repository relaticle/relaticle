<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Models\Team;
use Danestves\LaravelPolar\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Relaticle\Chat\Services\CreditService;

final readonly class SyncTeamPlanFromSubscription
{
    public function __construct(private CreditService $credits) {}

    public function handle(Team $team, Subscription $subscription): void
    {
        $subscriptionPlan = $this->planForProduct($subscription->product_id);

        if (! $subscriptionPlan instanceof Plan) {
            Log::warning('Polar subscription product is not mapped to a plan', [
                'team_id' => $team->getKey(),
                'subscription_id' => $subscription->polar_id,
                'product_id' => $subscription->product_id,
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

        DB::transaction(function () use ($team, $target): void {
            $team->plan = $target;
            $team->save();

            $this->credits->resetPeriod($team);
        });
    }

    private function targetPlan(Team $team, Subscription $subscription, Plan $subscriptionPlan): ?Plan
    {
        if ($subscription->valid()) {
            return $subscriptionPlan;
        }

        // Only downgrade a plan this subscription granted — a sysadmin-assigned
        // plan (e.g. Enterprise) must survive an unrelated subscription ending.
        if ($team->plan === $subscriptionPlan) {
            return Plan::default();
        }

        return null;
    }

    private function planForProduct(string $productId): ?Plan
    {
        /** @var array<string, string|null> $products */
        $products = config('services.polar.products', []);

        foreach ($products as $plan => $mappedProductId) {
            if ($mappedProductId !== null && $mappedProductId === $productId) {
                return Plan::tryFrom($plan);
            }
        }

        return null;
    }
}
