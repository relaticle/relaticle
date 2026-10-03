<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\Workspace;
use App\Services\WorkspaceActivationFacts;

final readonly class ModelAccess
{
    public function planFor(?Workspace $workspace): Plan
    {
        if (! $workspace instanceof Workspace) {
            return Plan::default();
        }

        return $this->isTrialLocked($workspace) ? Plan::Free : $workspace->plan;
    }

    public function isTrialLocked(?Workspace $workspace): bool
    {
        if (! $workspace instanceof Workspace) {
            return false;
        }

        // Resolved per call: the facts are scoped to one request or job, and a constructor
        // copy would outlive the job it was built for inside a long-running worker.
        return $workspace->billingStatus() === BillingStatus::Trialing
            && ! resolve(WorkspaceActivationFacts::class)->hasOwnRecord($workspace);
    }
}
