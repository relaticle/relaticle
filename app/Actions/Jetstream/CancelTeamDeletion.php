<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use App\Notifications\TeamDeletionCancelledNotification;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class CancelTeamDeletion
{
    public function cancel(User $user, Team $team): void
    {
        throw_unless($user->ownsTeam($team), AuthorizationException::class);

        $team->forceFill(['scheduled_deletion_at' => null])->save();

        /** @var User $owner */
        $owner = $team->owner;

        $owner->notify(new TeamDeletionCancelledNotification($team));
    }
}
