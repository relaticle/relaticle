<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceWriteAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class TaskPolicy
{
    use ChecksWorkspaceWriteAccess;
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->canViewAnyInCurrentWorkspace($user);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->canViewInWorkspace($user, $task->workspace_id);
    }

    public function create(User $user): bool
    {
        return $this->canCreateInCurrentWorkspace($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->canWriteInWorkspace($user, $task->workspace_id);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->canDeleteInWorkspace($user, $task->workspace_id);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canDeleteInCurrentWorkspace($user);
    }

    public function restore(User $user, Task $task): bool
    {
        return $this->canDeleteInWorkspace($user, $task->workspace_id);
    }

    public function restoreAny(User $user): bool
    {
        return $this->canDeleteInCurrentWorkspace($user);
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return $this->canForceDeleteInWorkspace($user, $task->workspace_id);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->canForceDeleteInWorkspace($user, $user->currentWorkspace?->getKey());
    }

    public function exportAny(User $user): bool
    {
        return $this->canExportInCurrentWorkspace($user);
    }
}
