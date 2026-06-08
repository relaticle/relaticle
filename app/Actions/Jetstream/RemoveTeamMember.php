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
     */
    private function authorize(User $user, Team $team, User $teamMember): void
    {
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
