<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Support\CustomFieldMerger;
use App\Support\TenantFkValidator;
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

        TenantFkValidator::assertOwnedMany($user, $data, [
            'company_ids' => Company::class,
            'people_ids' => People::class,
            'opportunity_ids' => Opportunity::class,
        ]);

        TenantFkValidator::assertUsersInWorkspace($user, $data, ['assignee_ids']);

        $attributeData = Arr::only($data, ['title', 'custom_fields']);
        /** @var array<int, string> $newAssigneeIds */
        $newAssigneeIds = [];

        $task = DB::transaction(function () use ($task, $attributeData, $data, &$newAssigneeIds): Task {
            $lockedTask = Task::query()
                ->whereKey($task->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = CustomFieldMerger::merge($lockedTask, $attributeData);
            $lockedTask->update($attributes);

            if (array_key_exists('company_ids', $data)) {
                $lockedTask->companies()->sync($data['company_ids']);
            }
            if (array_key_exists('people_ids', $data)) {
                $lockedTask->people()->sync($data['people_ids']);
            }
            if (array_key_exists('opportunity_ids', $data)) {
                $lockedTask->opportunities()->sync($data['opportunity_ids']);
            }
            if (array_key_exists('assignee_ids', $data)) {
                $changes = $lockedTask->assignees()->sync($data['assignee_ids']);
                $newAssigneeIds = $changes['attached'];
            }

            return $lockedTask->refresh();
        });

        $this->notifyAssignees->execute($task, $newAssigneeIds);

        return $task->load('customFieldValues.customField.options');
    }
}
