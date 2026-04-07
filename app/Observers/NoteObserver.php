<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Note;

final readonly class NoteObserver
{
    public function saved(Note $note): void
    {
        $note->invalidateRelatedSummaries();
    }

    public function deleted(Note $note): void
    {
        $note->invalidateRelatedSummaries();
    }
}
