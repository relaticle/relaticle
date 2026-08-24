<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use App\Notifications\TeamMemberRemovedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Laravel\Jetstream\Events\TeamMemberRemoved;

final readonly class RemoveTeamMember implements RemovesTeamMembers
{
    /**
     * Remove the team member from the given team.
     */
    public function remove(User $user, Team $team, User $teamMember): void
    {
        $this->authorize($user, $team, $teamMember);

        $this->ensureUserDoesNotOwnTeam($teamMember, $team);

        $team->removeUser($teamMember);

        event(new TeamMemberRemoved($team, $teamMember));

        $teamMember->notify(new TeamMemberRemovedNotification($team));
    }

    /**
     * Authorize that the user can remove the team member.
     *
     * The self-removal branch below is what lets someone leave a team without
     * holding the removeTeamMember permission, so it has to be paired with the
     * membership check: on its own it authorizes against *any* team, and the
     * removal notification then names a workspace the caller was never part of.
     */
    private function authorize(User $user, Team $team, User $teamMember): void
    {
        throw_unless($teamMember->belongsToTeam($team), AuthorizationException::class);

        throw_if(! Gate::forUser($user)->check('removeTeamMember', $team) &&
            $user->id !== $teamMember->id, AuthorizationException::class);
    }

    /**
     * Ensure that the currently authenticated user does not own the team.
     */
    private function ensureUserDoesNotOwnTeam(User $teamMember, Team $team): void
    {
        /** @var User $owner */
        $owner = $team->owner;
        if ($teamMember->id === $owner->id) {
            throw ValidationException::withMessages([
                'team' => [__('You may not leave a workspace that you created.')],
            ])->errorBag('removeTeamMember');
        }
    }
}
