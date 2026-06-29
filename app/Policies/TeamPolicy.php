<?php

declare(strict_types=1);

namespace App\Policies;

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
        // When enabled, only owners/administrators of an existing team may
        // create new teams (so the first team can still be created by anyone).
        if (config('relaticle.teams.creation_admins_only') && ! $user->administersAnyTeam()) {
            return false;
        }

        return $user->ownedTeams()->count() < 3;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can add team members.
     */
    public function addTeamMember(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can update team member permissions.
     */
    public function updateTeamMember(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can remove team members.
     */
    public function removeTeamMember(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
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
