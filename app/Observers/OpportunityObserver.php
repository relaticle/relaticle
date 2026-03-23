<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Opportunity;
use App\Models\User;

final readonly class OpportunityObserver
{
    public function creating(Opportunity $opportunity): void
    {
        if (auth()->check()) {
            /** @var User $user */
            $user = auth()->user();
            $opportunity->creator_id ??= $user->getKey();
            $opportunity->team_id ??= $user->currentTeam->getKey();
        }
    }

    /**
     * Handle the Opportunity "saved" event.
     * Invalidate AI summary when opportunity data changes.
     */
    public function saved(Opportunity $opportunity): void
    {
        $opportunity->invalidateAiSummary();
    }
}
