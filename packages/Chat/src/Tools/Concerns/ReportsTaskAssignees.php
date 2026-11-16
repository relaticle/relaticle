<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait ReportsTaskAssignees
{
    /** @return array{assignees: array<int, array{id: string, name: string}>} */
    protected function extraPayload(Model $model): array
    {
        /** @var Task $task */
        $task = $model;

        return [
            'assignees' => $task->assignees
                ->map(fn (User $assignee): array => ['id' => (string) $assignee->getKey(), 'name' => (string) $assignee->name])
                ->values()
                ->all(),
        ];
    }
}
