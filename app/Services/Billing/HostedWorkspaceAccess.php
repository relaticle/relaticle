<?php

declare(strict_types=1);

namespace App\Services\Billing;

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

        return $workspace->billingStatus()->grantsAccess();
    }

    public function isPaused(Workspace $workspace): bool
    {
        return ! $this->allows($workspace);
    }
}
