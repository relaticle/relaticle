<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTask
{
    public function __construct(
        private NotifyTaskAssignees $notifyAssignees,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \Throwable
     */
    public function execute(User $user, Task $task, array $data): Task
    {
        abort_unless($user->can('update', $task), 403);

        $attributes = Arr::only($data, ['title', 'custom_fields']);

        $previousAssigneeIds = $task->assignees()->pluck('users.id')->all();

        $task = DB::transaction(function () use ($task, $attributes, $data): Task {
            $task->update($attributes);

            if (array_key_exists('company_ids', $data)) {
                $task->companies()->sync($data['company_ids']);
            }
            if (array_key_exists('people_ids', $data)) {
                $task->people()->sync($data['people_ids']);
            }
            if (array_key_exists('opportunity_ids', $data)) {
                $task->opportunities()->sync($data['opportunity_ids']);
            }
            if (array_key_exists('assignee_ids', $data)) {
                $task->assignees()->sync($data['assignee_ids']);
            }

            return $task->refresh();
        });

        $this->notifyAssignees->execute($task, $previousAssigneeIds);

        return $task;
    }
}
