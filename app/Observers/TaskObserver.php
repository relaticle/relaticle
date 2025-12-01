<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Task;

final readonly class TaskObserver
{
    public function creating(Task $task): void
    {
        if (auth('web')->check()) {
            $task->creator_id = auth('web')->id();
            $task->team_id = auth('web')->user()->currentTeam->getKey();
        }
    }

    /**
     * Handle the Task "saved" event.
     * Invalidate AI summaries for all related records.
     */
    public function saved(Task $task): void
    {
        $this->invalidateRelatedSummaries($task);
    }

    /**
     * Handle the Task "deleted" event.
     * Invalidate AI summaries for all related records.
     */
    public function deleted(Task $task): void
    {
        $this->invalidateRelatedSummaries($task);
    }

    /**
     * Invalidate AI summaries for all records related to this task.
     */
    private function invalidateRelatedSummaries(Task $task): void
    {
        $task->companies->each(function (\App\Models\Company $company): void {
            $company->invalidateAiSummary();
        });
        $task->people->each(function (\App\Models\People $person): void {
            $person->invalidateAiSummary();
        });
        $task->opportunities->each(function (\App\Models\Opportunity $opportunity): void {
            $opportunity->invalidateAiSummary();
        });
    }
}
