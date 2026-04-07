<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Models\Task;
use App\Models\User;

final readonly class DeleteTask
{
    public function execute(User $user, Task $task): void
    {
        abort_unless($user->can('delete', $task), 403);

        $task->delete();
    }
}
