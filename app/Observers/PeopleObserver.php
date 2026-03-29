<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\People;

final readonly class PeopleObserver
{
    /**
     * Handle the People "saved" event.
     * Invalidate AI summary when person data changes.
     */
    public function saved(People $people): void
    {
        $people->invalidateAiSummary();
    }
}
