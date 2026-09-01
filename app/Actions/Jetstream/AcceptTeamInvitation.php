<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Jetstream;

/**
 * Callers confirm the invitation is valid and the email matches, because each
 * surface renders a mismatch differently. Deletion state and the revoked-or-
 * expired race are enforced here so no caller can report a join that did not
 * happen; both refuse with an HttpException the caller may catch.
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
                $this->addMember($user, $team, $this->registeredRole($team, $locked->role));
            }

            $locked->delete();

            return true;
        });

        abort_unless($joined, 410, __('teams.accept.no_longer_valid'));

        $user->unsetRelation('teams');
        $user->switchTeam($team);

        return $team;
    }

    // An unregistered or absent role key fails AddTeamMember's validation from
    // inside the transaction, dead-ending the invitee on a page that never completes.
    private function registeredRole(Team $team, ?string $role): string
    {
        if ($role !== null && Jetstream::findRole($role) !== null) {
            return $role;
        }

        return $team->invite_link_default_role;
    }

    /**
     * ApplyTenantScopes scopes User to the tenant being browsed, which is not the
     * team being joined, so every lookup AddsTeamMembers makes would miss.
     * Suspends only that one named scope entry and restores exactly what it was.
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
