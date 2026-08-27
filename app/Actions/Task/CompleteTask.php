<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Enums\CustomFields\TaskField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Task;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

final readonly class CompleteTask
{
    public function execute(User $user, Task $task): void
    {
        abort_unless($user->can('update', $task), 403);

        // The custom-fields tenant scope and saveCustomFieldValue() both resolve
        // against the ambient tenant context, so pin it to the task's own team:
        // a caller holding a task from another of the user's teams must not get
        // a foreign status field id written onto it.
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($task->team_id);

        try {
            /** @var CustomField|null $status */
            $status = CustomField::query()
                ->with('options')
                ->forEntity(Task::class)
                ->where('code', TaskField::STATUS)
                ->first();

            $done = $status?->options->firstWhere('name', 'Done');

            abort_unless(
                $status instanceof CustomField && $done instanceof CustomFieldOption,
                422,
                __('This workspace has no Done task status.'),
            );

            $task->saveCustomFieldValue($status, $done->getKey());
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }
    }
}
