<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class WorkspacePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentWorkspace !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Workspace $workspace): bool
    {
        return $user->belongsToWorkspace($workspace);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->ownedWorkspaces()->count() < (int) config('relaticle.workspaces.max_owned_per_user');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Workspace $workspace): bool
    {
        return $user->ownsWorkspace($workspace);
    }

    /**
     * Owner and Admin may invite, revoke, and change member roles.
     * Renaming, deleting, billing, and custom fields stay owner-only.
     */
    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $user->ownsWorkspace($workspace)
            || $user->hasWorkspaceRoleForWorkspaceId($workspace->id, WorkspaceRole::Admin->value);
    }

    /**
     * Granting or revoking Admin is the owner's alone, so an Admin cannot
     * escalate a peer or themselves.
     */
    public function promoteToAdmin(User $user, Workspace $workspace): bool
    {
        return $user->ownsWorkspace($workspace);
    }

    /**
     * Determine whether the user can add workspace members.
     */
    public function addWorkspaceMember(User $user, Workspace $workspace): bool
    {
        return $this->manageMembers($user, $workspace);
    }

    /**
     * Determine whether the user can update workspace member permissions.
     */
    public function updateWorkspaceMember(User $user, Workspace $workspace): bool
    {
        return $this->manageMembers($user, $workspace);
    }

    /**
     * Determine whether the user can remove workspace members.
     */
    public function removeWorkspaceMember(User $user, Workspace $workspace): bool
    {
        return $this->manageMembers($user, $workspace);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->ownsWorkspace($workspace);
    }

    public function deleteAny(): bool
    {
        return false;
    }

    public function restore(User $user, Workspace $workspace): bool
    {
        return $user->ownsWorkspace($workspace);
    }

    public function restoreAny(): bool
    {
        return false;
    }

    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return $user->ownsWorkspace($workspace);
    }

    public function forceDeleteAny(): bool
    {
        return false;
    }
}
