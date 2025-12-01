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

    /**
     * Handle the Note "saved" event.
     * Invalidate AI summaries for all related records.
     */
    public function saved(Note $note): void
    {
        $this->invalidateRelatedSummaries($note);
    }

    /**
     * Handle the Note "deleted" event.
     * Invalidate AI summaries for all related records.
     */
    public function deleted(Note $note): void
    {
        $this->invalidateRelatedSummaries($note);
    }

    /**
     * Invalidate AI summaries for all records related to this note.
     */
    private function invalidateRelatedSummaries(Note $note): void
    {
        $note->companies->each(function (\App\Models\Company $company): void {
            $company->invalidateAiSummary();
        });
        $note->people->each(function (\App\Models\People $person): void {
            $person->invalidateAiSummary();
        });
        $note->opportunities->each(function (\App\Models\Opportunity $opportunity): void {
            $opportunity->invalidateAiSummary();
        });
    }
}
