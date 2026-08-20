<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\AddsTeamMembers;

/**
 * Joins an already-identity-verified user to the invitation's team and
 * removes the invitation. Called from both the accept-flow controller and
 * (Task 9) a Livewire "you've been invited" card, so it takes no request or
 * response and does not touch the view layer.
 *
 * Every caller is expected to have already confirmed the invitation is valid
 * and that the user's email matches it — those checks stay caller-specific
 * because each surface renders a different UX for a mismatch. Deletion state
 * has no such reason to vary, so both the user's and the team's scheduled-
 * deletion status are enforced here, where every caller inherits them. An
 * invitation that is revoked or expires between the caller's check and this
 * action's lock is refused the same way, so no caller can report a join that
 * did not happen. On a refusal this throws Laravel's HttpException (403 or
 * 410, matching the abort() the accept-flow controller used to perform
 * inline); callers that need a renderable response rather than a raw HTTP
 * error must catch it.
 */
final readonly class AcceptTeamInvitation
{
    public function __construct(
        private AddsTeamMembers $adder,
    ) {}

    public function execute(User $user, TeamInvitation $invitation): Team
    {
        $team = $invitation->team;

        abort_if($user->isScheduledForDeletion(), 403, __('teams.accept.account_deleting'));
        abort_if($team->isScheduledForDeletion(), 410, __('teams.accept.team_deleting'));

        $joined = DB::transaction(function () use ($user, $team, $invitation): bool {
            $locked = TeamInvitation::query()->lockForUpdate()->find($invitation->id);

            if ($locked === null || $locked->isExpired()) {
                // Revoked or expired between the caller's check and this lock.
                // A user who is already a member reached this branch because a
                // concurrent accept won the race, so that stays a success;
                // anyone else never joined and must not be told they did.
                $user->unsetRelation('teams');

                return $user->belongsToTeam($team);
            }

            if (! $user->belongsToTeam($team)) {
                $this->addMember($user, $team, $locked->role);
            }

            $locked->delete();

            return true;
        });

        abort_unless($joined, 410, __('teams.accept.no_longer_valid'));

        $user->unsetRelation('teams');
        $user->switchTeam($team);

        return $team;
    }

    /**
     * Inside a Filament panel request, ApplyTenantScopes globally scopes User to
     * members of the currently-viewed tenant. $team here is — by definition —
     * usually a *different* team than the one the caller is currently browsing,
     * so every User lookup AddsTeamMembers performs internally (the team owner,
     * hasUserWithEmail(), findUserByEmailOrFail()) would silently miss under that
     * scope.
     *
     * Suspend only that one named scope entry on User for the duration of the
     * add, then restore that exact entry — a Closure or a Scope instance,
     * whichever it was — afterward. No other scope on User, and no scope on any
     * other model, is ever read or touched. When the scope was never registered
     * in the first place (the accept-flow controller, queued jobs, tests — none
     * of which run behind the panel's tenant middleware), skip the suspension
     * entirely rather than registering a scope that didn't exist before.
     */
    private function addMember(User $invitee, Team $team, ?string $role): void
    {
        $scopeName = filament()->getTenancyScopeName();
        $originalScope = $invitee->getGlobalScopes()[$scopeName] ?? null;

        if ($originalScope === null) {
            $this->attachViaOwner($invitee, $team, $role);

            return;
        }

        User::addGlobalScope($scopeName, fn (Builder $query): Builder => $query);

        try {
            $this->attachViaOwner($invitee, $team, $role);
        } finally {
            User::addGlobalScope($scopeName, $originalScope);
        }
    }

    private function attachViaOwner(User $invitee, Team $team, ?string $role): void
    {
        /** @var User $owner */
        $owner = $team->owner;

        $this->adder->add($owner, $team, $invitee->email, $role);
    }
}
