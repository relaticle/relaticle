<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Notifications\UserDeletionCancelledNotification;
use Illuminate\Support\Facades\DB;

final readonly class CancelUserDeletion
{
    public function cancel(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill(['scheduled_deletion_at' => null])->save();

            $user->ownedTeams()
                ->where('personal_team', true)
                ->update(['scheduled_deletion_at' => null]);
        });

        $user->notify(new UserDeletionCancelledNotification($user));
    }
}
