<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Plan;
use App\Features\Billing;
use App\Models\Team;
use Laravel\Pennant\Feature;

final readonly class HostedWorkspaceAccess
{
    public function allows(Team $team): bool
    {
        if (! Feature::active(Billing::class)) {
            return true;
        }

        if ($team->hosted_free_grandfathered_at !== null) {
            return true;
        }

        if ($team->subscription()?->valid() === true) {
            return true;
        }

        if ($team->plan === Plan::Enterprise) {
            return true;
        }

        if ($team->onGenericTrial()) {
            return true;
        }

        if ($team->trial_ends_at !== null) {
            return false;
        }

        return $team->plan === Plan::Pro;
    }

    public function isPaused(Team $team): bool
    {
        return ! $this->allows($team);
    }
}
