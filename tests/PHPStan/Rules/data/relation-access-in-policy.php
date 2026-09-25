<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\Task;
use App\Models\User;

final class RelationAccessPolicyFixture
{
    public function view(User $user, Company $company): bool
    {
        return $user->belongsToWorkspace($company->workspace);
    }

    public function update(User $user, Task $task): bool
    {
        return $task->creator->getKey() === $user->getKey();
    }

    public function delete(User $user, Task $task): bool
    {
        return $task->workspace->getKey() === $user->currentWorkspace?->getKey();
    }

    public function restore(User $user, ?Task $task): bool
    {
        return $task?->workspace?->getKey() === $user->currentWorkspace?->getKey();
    }
}
