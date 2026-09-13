<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Actions\Billing\CancelWorkspaceSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceDeletionScheduledNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ScheduleWorkspaceDeletion
{
    public function __construct(private CancelWorkspaceSubscription $cancelSubscription) {}

    public function schedule(User $user, Workspace $workspace): void
    {
        throw_unless($user->ownsWorkspace($workspace), AuthorizationException::class);

        if ($workspace->isPersonalWorkspace()) {
            throw ValidationException::withMessages([
                'workspace' => ['Personal workspaces cannot be deleted directly.'],
            ]);
        }

        DB::transaction(function () use ($workspace): void {
            $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(config('relaticle.deletion.grace_period_days'))])->save();

            $workspace->workspaceInvitations()->delete();
        });

        $this->cancelSubscription->execute($workspace);

        /** @var User $owner */
        $owner = $workspace->owner;

        $owner->notify(new WorkspaceDeletionScheduledNotification($workspace));
    }
}
