<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Workspace;
use Carbon\CarbonInterface;

final readonly class RestoreWorkspaceTrial
{
    /**
     * Put back a generic trial that Cashier cleared while recording a
     * subscription which never granted access.
     */
    public function execute(Workspace $workspace, CarbonInterface $trialEndsAt): void
    {
        // Cashier cleared the column on its own instance, so refresh before
        // writing the original value back or the model is not dirty.
        $workspace->refresh();

        $workspace->forceFill(['trial_ends_at' => $trialEndsAt])->save();
    }
}
