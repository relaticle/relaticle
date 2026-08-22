<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Enums\CustomFields\TaskField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Task;
use App\Models\User;

final readonly class CompleteTask
{
    public function execute(User $user, Task $task): void
    {
        abort_unless($user->can('update', $task), 403);

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
            'This workspace has no Done task status.',
        );

        $task->saveCustomFieldValue($status, $done->getKey());
    }
}
