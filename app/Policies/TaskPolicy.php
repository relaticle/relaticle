<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceWriteAccess;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class TaskPolicy
{
    use ChecksWorkspaceWriteAccess;
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentWorkspace !== null;
    }

    public function view(User $user, Task $task): bool
    {
        return $user->belongsToWorkspaceId($task->workspace_id);
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
        return $this->canWriteInWorkspace($user, $task->workspace_id);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canCreateInCurrentWorkspace($user);
    }

    public function restore(User $user, Task $task): bool
    {
        return $this->canWriteInWorkspace($user, $task->workspace_id);
    }

    public function restoreAny(User $user): bool
    {
        return $this->canCreateInCurrentWorkspace($user);
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return $user->hasWorkspaceRoleForWorkspaceId($task->workspace_id, 'admin');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasWorkspaceRole(Filament::getTenant(), 'admin');
    }
}
