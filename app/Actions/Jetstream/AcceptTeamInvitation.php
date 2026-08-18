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
 * and that the user's email matches it — this action only handles the write.
 */
final readonly class AcceptTeamInvitation
{
    public function __construct(
        private AddsTeamMembers $adder,
    ) {}

    public function execute(User $user, TeamInvitation $invitation): Team
    {
        $team = $invitation->team;

        DB::transaction(function () use ($user, $team, $invitation): void {
            $locked = TeamInvitation::query()->lockForUpdate()->find($invitation->id);

            if ($locked === null || $locked->isExpired()) {
                return;
            }

            if (! $user->belongsToTeam($team)) {
                $this->addMember($user, $team, $locked->role);
            }

            $locked->delete();
        });

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
     * scope. Suspend it for the duration of the add, then restore it exactly as
     * it was — including "not registered at all", for callers outside a panel
     * request (the accept-flow controller, queued jobs, tests).
     */
    private function addMember(User $invitee, Team $team, ?string $role): void
    {
        $scopeName = filament()->getTenancyScopeName();

        if (! User::hasGlobalScope($scopeName)) {
            $this->attachViaOwner($invitee, $team, $role);

            return;
        }

        $originalScopes = User::getAllGlobalScopes();

        User::addGlobalScope($scopeName, fn (Builder $query): Builder => $query);

        try {
            $this->attachViaOwner($invitee, $team, $role);
        } finally {
            User::setAllGlobalScopes($originalScopes);
        }
    }

    private function attachViaOwner(User $invitee, Team $team, ?string $role): void
    {
        /** @var User $owner */
        $owner = $team->owner;

        $this->adder->add($owner, $team, $invitee->email, $role);
    }
}
