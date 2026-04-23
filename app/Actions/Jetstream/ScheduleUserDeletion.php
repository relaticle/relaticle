<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Notifications\UserDeletionScheduledNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ScheduleUserDeletion
{
    public function schedule(User $user): void
    {
        $this->ensureUserCanBeDeleted($user);

        DB::transaction(function () use ($user): void {
            $deletionDate = now()->addDays(config('relaticle.deletion.grace_period_days'));

            $user->forceFill(['scheduled_deletion_at' => $deletionDate])->save();

            $user->ownedTeams()
                ->where('personal_team', true)
                ->update(['scheduled_deletion_at' => $deletionDate]);
        });

        $user->notify(new UserDeletionScheduledNotification($user));
    }

    private function ensureUserCanBeDeleted(User $user): void
    {
        $teamsWithMembers = $user->ownedTeams()
            ->where('personal_team', false)
            ->whereHas('users')
            ->pluck('name');

        if ($teamsWithMembers->isNotEmpty()) {
            throw ValidationException::withMessages([
                'team' => ["Transfer ownership of these teams before deleting your account: {$teamsWithMembers->implode(', ')}"],
            ]);
        }
    }
}
