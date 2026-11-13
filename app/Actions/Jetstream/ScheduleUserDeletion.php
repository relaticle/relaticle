<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Enums\DeletionReason;
use App\Features\AccountDeletion;
use App\Models\User;
use App\Notifications\UserDeletionScheduledNotification;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

final readonly class ScheduleUserDeletion
{
    public function schedule(User $user): void
    {
        abort_unless(Feature::for($user)->active(AccountDeletion::class), 403);

        $user->ensureCanBeDeleted();

        DB::transaction(function () use ($user): void {
            $deletionDate = now()->addDays(config('relaticle.deletion.grace_period_days'));

            $user->forceFill(['scheduled_deletion_at' => $deletionDate])->save();

            $user->ownedWorkspaces()
                ->where('personal_workspace', true)
                ->update([
                    'scheduled_deletion_at' => $deletionDate,
                    'deletion_reason' => DeletionReason::Requested,
                ]);
        });

        $user->notify(new UserDeletionScheduledNotification($user));
    }
}
