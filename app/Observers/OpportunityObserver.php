<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Opportunity;

final readonly class OpportunityObserver
{
    /**
     * Handle the Opportunity "saved" event.
     * Invalidate AI summary when opportunity data changes.
     */
    public function saved(Opportunity $opportunity): void
    {
        $opportunity->invalidateAiSummary();
    }
}
