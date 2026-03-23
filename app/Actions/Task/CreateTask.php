<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Enums\CreationSource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class CreateTask
{
    public function __construct(
        private NotifyTaskAssignees $notifyAssignees,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): Task
    {
        abort_unless($user->can('create', Task::class), 403);

        $companyIds = Arr::pull($data, 'company_ids');
        $peopleIds = Arr::pull($data, 'people_ids');
        $opportunityIds = Arr::pull($data, 'opportunity_ids');
        $assigneeIds = Arr::pull($data, 'assignee_ids');

        $attributes = Arr::only($data, ['title', 'custom_fields']);
        $attributes['creation_source'] = $source;

        $task = DB::transaction(function () use ($attributes, $companyIds, $peopleIds, $opportunityIds, $assigneeIds): Task {
            $task = Task::query()->create($attributes);

            if ($companyIds !== null) {
                $task->companies()->sync($companyIds);
            }
            if ($peopleIds !== null) {
                $task->people()->sync($peopleIds);
            }
            if ($opportunityIds !== null) {
                $task->opportunities()->sync($opportunityIds);
            }
            if ($assigneeIds !== null) {
                $task->assignees()->sync($assigneeIds);
            }

            return $task;
        });

        $this->notifyAssignees->execute($task);

        return $task;
    }
}
