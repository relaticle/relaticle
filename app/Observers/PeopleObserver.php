<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\People;

final readonly class PeopleObserver
{
    public function creating(People $people): void
    {
        if (auth('web')->check()) {
            $people->creator_id = auth('web')->id();
            $people->team_id = auth('web')->user()->currentTeam->getKey();
        }
    }

    /**
     * Handle the People "saved" event.
     * Invalidate AI summary when person data changes.
     */
    public function saved(People $people): void
    {
        $people->invalidateAiSummary();
    }
}
