<?php

declare(strict_types=1);

namespace App\Services\Billing;

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

        if ($workspace->billingStatus() === BillingStatus::TrialEnded) {
            return Plan::Free->credits();
        }

        return $workspace->plan->credits();
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
}
