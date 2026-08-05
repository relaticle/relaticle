<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Team;
use Illuminate\Support\Carbon;

final readonly class RestoreWorkspaceTrial
{
    /**
     * Put back a generic trial that Cashier cleared while recording a
     * subscription which never granted access.
     */
    public function execute(Team $team, Carbon $trialEndsAt): void
    {
        // Cashier cleared the column on its own instance, so refresh before
        // writing the original value back or the model is not dirty.
        $team->refresh();

        $team->forceFill(['trial_ends_at' => $trialEndsAt])->save();
    }
}
