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
        return $user->belongsToTeam($company->team);
    }

    public function update(User $user, Task $task): bool
    {
        return $task->creator->getKey() === $user->getKey();
    }

    public function delete(User $user, Task $task): bool
    {
        return $task->team->getKey() === $user->currentTeam?->getKey();
    }

    public function restore(User $user, ?Task $task): bool
    {
        return $task?->team?->getKey() === $user->currentTeam?->getKey();
    }
}
