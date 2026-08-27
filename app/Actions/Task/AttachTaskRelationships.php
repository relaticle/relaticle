<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Models\Task;
use App\Models\User;
use App\Support\CrmRelationshipSync;
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
        abort_unless($task->team_id === $user->current_team_id, 403);

        TenantFkValidator::assertOwnedMany($user, $data, CrmRelationshipSync::OWNED_MODELS);
        TenantFkValidator::assertUsersInWorkspace($user, $data, ['assignee_ids']);

        /** @var array<int, string> $newAssigneeIds */
        $newAssigneeIds = [];

        DB::transaction(function () use ($task, $data, &$newAssigneeIds): void {
            $lockedTask = Task::query()
                ->whereKey($task->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $attached = CrmRelationshipSync::attach($lockedTask, $data);
            $newAssigneeIds = $attached['assignee_ids'] ?? [];
        });

        $task->refresh();
        $this->notifyAssignees->execute($task, $newAssigneeIds);

        return $task;
    }
}
