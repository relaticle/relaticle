<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Note;
use App\Models\User;

final readonly class NoteObserver
{
    public function creating(Note $note): void
    {
        if (auth()->check()) {
            /** @var User $user */
            $user = auth()->user();
            $note->creator_id = $user->getKey();
            $note->team_id = $user->currentTeam->getKey();
        }
    }

    public function saved(Note $note): void
    {
        $note->invalidateRelatedSummaries();
    }

    public function deleted(Note $note): void
    {
        $note->invalidateRelatedSummaries();
    }
}
