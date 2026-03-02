<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\People;
use App\Models\User;

final readonly class PeopleObserver
{
    public function creating(People $people): void
    {
        if (auth()->check()) {
            /** @var User $user */
            $user = auth()->user();
            $people->creator_id ??= $user->getKey();
            $people->team_id ??= $user->currentTeam->getKey();
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
