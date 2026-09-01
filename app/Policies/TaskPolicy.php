<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamWriteAccess;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class TaskPolicy
{
    use ChecksTeamWriteAccess;
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    public function view(User $user, Task $task): bool
    {
        return $user->belongsToTeamId($task->team_id);
    }

    public function create(User $user): bool
    {
        return $this->canCreateInCurrentTeam($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->canWriteInTeam($user, $task->team_id);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->canWriteInTeam($user, $task->team_id);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canCreateInCurrentTeam($user);
    }

    public function restore(User $user, Task $task): bool
    {
        return $this->canWriteInTeam($user, $task->team_id);
    }

    public function restoreAny(User $user): bool
    {
        return $this->canCreateInCurrentTeam($user);
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return $user->hasTeamRoleForTeamId($task->team_id, 'admin');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasTeamRole(Filament::getTenant(), 'admin');
    }
}
