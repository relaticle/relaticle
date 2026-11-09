<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;

final readonly class NotifyTaskAssignees
{
    /**
     * @param  array<int, string>  $assigneeIds
     */
    public function execute(Task $task, array $assigneeIds, ?User $actor): void
    {
        if ($assigneeIds === []) {
            return;
        }

        $recipients = User::query()->whereIn('id', array_values(array_unique($assigneeIds)))->get();

        foreach ($recipients as $recipient) {
            $recipient->notify(new TaskAssignedNotification($task, $actor));
        }
    }
}
