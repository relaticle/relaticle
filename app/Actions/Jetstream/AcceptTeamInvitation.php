<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
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
                /** @var User $owner */
                $owner = $team->owner;

                $this->adder->add($owner, $team, $user->email, $locked->role);
            }

            $locked->delete();
        });

        $user->unsetRelation('teams');
        $user->switchTeam($team);

        return $team;
    }
}
