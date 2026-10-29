<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Enums\StripeSubscriptionStatus;
use App\Enums\SubscriptionType;
use App\Mail\ProEndedMail;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Services\CreditService;

final readonly class SyncWorkspacePlanFromSubscription
{
    public function __construct(private CreditService $credits) {}

    public function execute(Workspace $workspace, Subscription $subscription): void
    {
        $subscriptionPlan = $this->subscriptionPlan($subscription);

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

        if ($target === Plan::default() && $workspace->owner instanceof User) {
            Mail::to($workspace->owner->email)->queue($this->endedMail($workspace, $subscriptionPlan));
        }
    }

    private function subscriptionPlan(Subscription $subscription): ?Plan
    {
        if (! in_array($subscription->type, SubscriptionType::managed(), true)) {
            return Plan::fromStripePrice($subscription->stripe_price);
        }

        return Plan::soldBy($subscription);
    }

    private function endedMail(Workspace $workspace, Plan $endedPlan): ProEndedMail
    {
        return $endedPlan->isManaged()
            ? ProEndedMail::afterManagedPlan($workspace, $endedPlan)
            : ProEndedMail::afterSubscription($workspace);
    }

    private function leavesPlanToManagedGrant(Workspace $workspace, Subscription $subscription): bool
    {
        return match ($subscription->type) {
            SubscriptionType::ManagedPending->value => true,
            SubscriptionType::Managed->value => $subscription->valid() || $this->hasOtherValidManagedSubscription($workspace, $subscription),
            default => false,
        };
    }

    private function hasOtherValidManagedSubscription(Workspace $workspace, Subscription $subscription): bool
    {
        return Subscription::query()
            ->where($workspace->getForeignKey(), $workspace->getKey())
            ->where('type', SubscriptionType::Managed->value)
            ->where('stripe_id', '!=', $subscription->stripe_id)
            ->get()
            ->contains(fn (Subscription $other): bool => $other->valid());
    }

    private function restartsLapsedManagedPlan(Workspace $workspace, Subscription $subscription, Plan $subscriptionPlan): bool
    {
        return $subscription->type === SubscriptionType::Managed->value
            && $subscription->valid()
            && $subscriptionPlan->isManaged()
            && $workspace->plan === Plan::default();
    }

    private function targetPlan(Workspace $workspace, Subscription $subscription, Plan $subscriptionPlan): ?Plan
    {
        if ($this->restartsLapsedManagedPlan($workspace, $subscription, $subscriptionPlan)) {
            return $subscriptionPlan;
        }

        if ($this->leavesPlanToManagedGrant($workspace, $subscription)) {
            return null;
        }

        if ($workspace->plan->isManaged() && $subscriptionPlan !== $workspace->plan) {
            return null;
        }

        if ($subscription->valid()) {
            return $subscriptionPlan;
        }

        // A subscription that never charged (abandoned or failed checkout) has
        // granted nothing, so it must not take anything away either.
        if (in_array($subscription->stripe_status, StripeSubscriptionStatus::neverGranted(), true)) {
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
