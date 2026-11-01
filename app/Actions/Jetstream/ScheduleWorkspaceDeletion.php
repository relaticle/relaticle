<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Contracts\Billing;
use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceDeletionScheduledNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ScheduleWorkspaceDeletion
{
    public function __construct(private Billing $billing) {}

    public function schedule(User $user, Workspace $workspace): void
    {
        throw_unless($user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::WorkspaceManage), AuthorizationException::class);

        if ($workspace->isPersonalWorkspace()) {
            throw ValidationException::withMessages([
                'workspace' => ['Personal workspaces cannot be deleted directly.'],
            ]);
        }

        if ($this->billing->blocksDeletion($workspace)) {
            throw ValidationException::withMessages([
                'workspace' => [__('workspaces.validation.managed_plan_before_deleting')],
            ]);
        }

        DB::transaction(function () use ($workspace): void {
            $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(config('relaticle.deletion.grace_period_days'))])->save();

            $workspace->workspaceInvitations()->delete();
        });

        $this->billing->cancelForDeletion($workspace, immediately: false);

        /** @var User $owner */
        $owner = $workspace->owner;

        $owner->notify(new WorkspaceDeletionScheduledNotification($workspace));
    }
}
