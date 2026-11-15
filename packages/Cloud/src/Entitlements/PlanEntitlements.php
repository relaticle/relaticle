<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Entitlements;

use App\Contracts\Entitlements;
use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\Workspace;
use Relaticle\Chat\Services\ModelAccess;
use Relaticle\Chat\Support\ModelDescriptor;

final readonly class PlanEntitlements implements Entitlements
{
    public function __construct(private ModelAccess $modelAccess) {}

    public function metersCredits(Workspace $workspace): bool
    {
        return true;
    }

    public function creditAllowance(Workspace $workspace): int
    {
        $workspace->loadMissing('subscriptions');

        if (! $workspace->plan->isManaged() && $workspace->subscription()?->pastDue() === true) {
            return Plan::Free->credits();
        }

        $status = $workspace->billingStatus();

        if ($status === BillingStatus::TrialEnded || $workspace->onExtendedTrial()) {
            return Plan::Free->credits();
        }

        if ($status === BillingStatus::Trialing && $this->onAutomaticTrial($workspace)) {
            return Plan::trialCredits();
        }

        return $workspace->plan->credits();
    }

    // An administrator's restart or extension ends later than the automatic span and keeps the plan allowance.
    private function onAutomaticTrial(Workspace $workspace): bool
    {
        return $workspace->pro_trial_used_at !== null
            && $workspace->trial_ends_at?->lessThanOrEqualTo($workspace->pro_trial_used_at->addDays(Workspace::PRO_TRIAL_DAYS)) === true;
    }

    public function allowsModel(?Workspace $workspace, ModelDescriptor $model): bool
    {
        return $model->allowedForPlan($this->modelAccess->planFor($workspace));
    }

    public function chatRateLimit(Workspace $workspace): int
    {
        return $workspace->plan->rateLimit();
    }

    public function allowsAccess(Workspace $workspace): bool
    {
        return $workspace->billingStatus()->grantsAccess();
    }

    public function mailboxLimit(Workspace $workspace): ?int
    {
        $workspace->loadMissing('subscriptions');

        if ($workspace->billingStatus() === BillingStatus::Trialing) {
            return Plan::trialConnectedMailboxes();
        }

        return $workspace->plan->connectedMailboxes();
    }
}
