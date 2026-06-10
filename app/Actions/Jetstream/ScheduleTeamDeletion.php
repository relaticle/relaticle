<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use App\Notifications\TeamDeletionScheduledNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ScheduleTeamDeletion
{
    public function schedule(User $user, Team $team): void
    {
        throw_unless($user->ownsTeam($team), AuthorizationException::class);

        if ($team->isPersonalTeam()) {
            throw ValidationException::withMessages([
                'team' => ['Personal workspaces cannot be deleted directly.'],
            ]);
        }

        DB::transaction(function () use ($team): void {
            $team->forceFill(['scheduled_deletion_at' => now()->addDays(config('relaticle.deletion.grace_period_days'))])->save();

            $team->teamInvitations()->delete();
        });

        /** @var User $owner */
        $owner = $team->owner;

        $owner->notify(new TeamDeletionScheduledNotification($team));
    }
}
