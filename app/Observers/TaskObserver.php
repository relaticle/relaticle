<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Task;

final readonly class TaskObserver
{
    public function saved(Task $task): void
    {
        $task->invalidateRelatedSummaries();
    }

    public function deleted(Task $task): void
    {
        $task->invalidateRelatedSummaries();
    }
}
