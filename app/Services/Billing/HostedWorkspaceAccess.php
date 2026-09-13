<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Plan;
use App\Features\Billing;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

final readonly class HostedWorkspaceAccess
{
    public function allows(Workspace $workspace): bool
    {
        if (! Feature::active(Billing::class)) {
            return true;
        }

        if ($workspace->hosted_free_grandfathered_at !== null) {
            return true;
        }

        if ($workspace->subscription()?->valid() === true) {
            return true;
        }

        if ($workspace->plan === Plan::Enterprise) {
            return true;
        }

        if ($workspace->onGenericTrial()) {
            return true;
        }

        if ($workspace->trial_ends_at !== null) {
            return false;
        }

        return $workspace->plan === Plan::Pro;
    }

    public function isPaused(Workspace $workspace): bool
    {
        return ! $this->allows($workspace);
    }
}
