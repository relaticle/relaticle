<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Queries;

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\Workspace;
use Laravel\Cashier\Subscription;
use Relaticle\Cloud\Billing\Data\BillingSummary;
use Relaticle\Cloud\Billing\Data\Renewal;

final readonly class BillingSummaryQuery
{
    public function forWorkspace(Workspace $workspace, bool $canManageBilling): BillingSummary
    {
        $isManaged = $workspace->plan->isManaged();
        $subscription = $workspace->subscription();
        $billed = $isManaged ? $workspace->managedSubscription() : $subscription;
        $renewal = $billed instanceof Subscription && $billed->valid() ? Renewal::remembered($billed->stripe_id) : null;
        $onTrial = ! $isManaged && $workspace->onGenericTrial();
        $subscribed = $subscription?->valid() ?? false;
        $legacyFree = $workspace->hosted_free_grandfathered_at !== null && $workspace->plan === Plan::Free && ! $onTrial && ! $subscribed;

        [$status, $tone, $headline] = match (true) {
            $isManaged && ($billed?->pastDue() ?? false) => ['past_due', 'danger', $this->managedTagline($billed, $canManageBilling)],
            $isManaged && ($billed?->onGracePeriod() ?? false) => ['managed', 'gray', __('cloud::billing.summary.ends', ['date' => $billed->ends_at?->isoFormat('ll')])],
            $isManaged => ['managed', 'gray', $renewal instanceof Renewal ? $this->renews($renewal) : $this->managedTagline($billed, $canManageBilling)],
            $workspace->billingStatus() === BillingStatus::PastDue => ['past_due', 'danger', $this->pastDue($renewal)],
            $subscription?->onGracePeriod() ?? false => ['canceling', 'warning', __('cloud::billing.summary.ends', ['date' => $subscription->ends_at?->isoFormat('ll')])],
            $subscription?->onTrial() ?? false => ['trialing', 'primary', $this->firstCharge($subscription, $renewal)],
            $onTrial => ['trialing', 'primary', $this->trialLeft($workspace)],
            $subscribed => ['active', 'success', $renewal instanceof Renewal ? $this->renews($renewal) : __('cloud::billing.manage.auto_renews')],
            $legacyFree => ['grandfathered', 'gray', __('cloud::billing.legacy_free.tagline')],
            default => ['managed', 'gray', __('cloud::billing.pro_plan.tagline')],
        };

        return new BillingSummary(
            plan: $this->planLabel($workspace, $legacyFree),
            status: __("cloud::billing.status.{$status}"),
            tone: $tone,
            headline: $headline,
            card: $renewal?->card(),
            refreshSubscriptionId: $billed instanceof Subscription && $billed->valid() && ! $renewal instanceof Renewal ? $billed->stripe_id : null,
        );
    }

    private function renews(Renewal $renewal): string
    {
        return __('cloud::billing.summary.renews', ['date' => $renewal->renewsAt->isoFormat('ll'), 'amount' => $renewal->amount()]);
    }

    private function firstCharge(Subscription $subscription, ?Renewal $renewal): string
    {
        return $renewal instanceof Renewal
            ? __('cloud::billing.summary.first_charge', ['date' => $renewal->renewsAt->isoFormat('ll'), 'amount' => $renewal->amount()])
            : __('cloud::billing.manage.first_charge', ['date' => $subscription->trial_ends_at?->isoFormat('ll')]);
    }

    private function pastDue(?Renewal $renewal): string
    {
        $owed = $renewal?->amountDue();

        return $owed === null ? __('cloud::billing.manage.past_due_tagline') : __('cloud::billing.summary.payment_failed', ['amount' => $owed]);
    }

    private function trialLeft(Workspace $workspace): string
    {
        $days = max(0, (int) ceil(now()->diffInDays($workspace->trial_ends_at ?? now())));

        return __('cloud::billing.trial.active_title').' ('.trans_choice('cloud::billing.trial.days_left', $days, ['days' => $days]).')';
    }

    private function managedTagline(?Subscription $managed, bool $canManageBilling): string
    {
        return $managed instanceof Subscription && $canManageBilling ? __('cloud::billing.managed.tagline_portal') : __('cloud::billing.managed.tagline');
    }

    private function planLabel(Workspace $workspace, bool $legacyFree): string
    {
        return match (true) {
            $legacyFree => __('billing.plans.legacy_free'),
            $workspace->plan === Plan::Free => __('billing.plans.cloud_pro'),
            default => $workspace->plan->marketingLabel(),
        };
    }
}
