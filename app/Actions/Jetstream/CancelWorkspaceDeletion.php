<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceDeletionCancelledNotification;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class CancelWorkspaceDeletion
{
    public function cancel(User $user, Workspace $workspace): void
    {
        throw_unless($user->ownsWorkspace($workspace), AuthorizationException::class);

        $workspace->forceFill(['scheduled_deletion_at' => null])->save();

        /** @var User $owner */
        $owner = $workspace->owner;

        $owner->notify(new WorkspaceDeletionCancelledNotification($workspace));
    }
}
