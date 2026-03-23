<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Task;
use App\Models\User;

final readonly class TaskObserver
{
    public function creating(Task $task): void
    {
        if (auth()->check()) {
            /** @var User $user */
            $user = auth()->user();
            $task->creator_id ??= $user->getKey();
            $task->team_id ??= $user->currentTeam->getKey();
        }
    }

    public function saved(Task $task): void
    {
        $task->invalidateRelatedSummaries();
    }

    public function deleted(Task $task): void
    {
        $task->invalidateRelatedSummaries();
    }
}
