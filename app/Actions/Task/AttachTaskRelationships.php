<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Support\TenantFkValidator;
use Illuminate\Support\Facades\DB;

final readonly class AttachTaskRelationships
{
    public function __construct(
        private NotifyTaskAssignees $notifyAssignees,
    ) {}

    /**
     * @param  array<string, mixed>  $data
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

        $previousAssigneeIds = $task->assignees()->pluck('users.id')->all();

        DB::transaction(function () use ($task, $data): void {
            if (array_key_exists('company_ids', $data)) {
                $task->companies()->syncWithoutDetaching($data['company_ids']);
            }
            if (array_key_exists('people_ids', $data)) {
                $task->people()->syncWithoutDetaching($data['people_ids']);
            }
            if (array_key_exists('opportunity_ids', $data)) {
                $task->opportunities()->syncWithoutDetaching($data['opportunity_ids']);
            }
            if (array_key_exists('assignee_ids', $data)) {
                $task->assignees()->syncWithoutDetaching($data['assignee_ids']);
            }
        });

        $task->refresh();
        $this->notifyAssignees->execute($task, $previousAssigneeIds);

        return $task;
    }
}
