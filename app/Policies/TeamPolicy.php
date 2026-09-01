<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class TeamPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->ownedTeams()->count() < (int) config('relaticle.workspaces.max_owned_per_user');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    /**
     * Owner and Admin may invite, revoke, and change member roles.
     * Renaming, deleting, billing, and custom fields stay owner-only.
     */
    public function manageMembers(User $user, Team $team): bool
    {
        return $user->ownsTeam($team)
            || $user->hasTeamRoleForTeamId($team->id, TeamRole::Admin->value);
    }

    /**
     * Granting or revoking Admin is the owner's alone, so an Admin cannot
     * escalate a peer or themselves.
     */
    public function promoteToAdmin(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can add team members.
     */
    public function addTeamMember(User $user, Team $team): bool
    {
        return $this->manageMembers($user, $team);
    }

    /**
     * Determine whether the user can update team member permissions.
     */
    public function updateTeamMember(User $user, Team $team): bool
    {
        return $this->manageMembers($user, $team);
    }

    /**
     * Determine whether the user can remove team members.
     */
    public function removeTeamMember(User $user, Team $team): bool
    {
        return $this->manageMembers($user, $team);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    public function deleteAny(): bool
    {
        return false;
    }

    public function restore(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    public function restoreAny(): bool
    {
        return false;
    }

    public function forceDelete(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    public function forceDeleteAny(): bool
    {
        return false;
    }
}
