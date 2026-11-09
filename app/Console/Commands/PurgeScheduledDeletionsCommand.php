<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PurgeUser;
use App\Jobs\PurgeWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\UserDeletionReminderNotification;
use App\Notifications\WorkspaceDeletionReminderNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

#[Description('Queue the permanent deletion of users and workspaces past their scheduled deletion date, and send reminders ahead of purge')]
#[Signature('app:purge-scheduled-deletions')]
final class PurgeScheduledDeletionsCommand extends Command
{
    public function handle(): int
    {
        $this->queueExpiredUsers();
        $this->queueExpiredWorkspaces();
        $this->sendReminders();

        return self::SUCCESS;
    }

    private function queueExpiredUsers(): void
    {
        $count = 0;

        User::query()
            ->expiredDeletion()
            ->eachById(function (User $user) use (&$count): void {
                dispatch(new PurgeUser($user));
                $count++;
            });

        $this->info("Queued {$count} user(s) for purge.");
    }

    private function queueExpiredWorkspaces(): void
    {
        $count = 0;

        Workspace::query()
            ->expiredDeletion()
            ->whereDoesntHave('owner', fn (Builder $owner): Builder => $owner->expiredDeletion())
            ->eachById(function (Workspace $workspace) use (&$count): void {
                dispatch(new PurgeWorkspace($workspace));
                $count++;
            });

        $this->info("Queued {$count} workspace(s) for purge.");
    }

    private function sendReminders(): void
    {
        $reminderDays = config('relaticle.deletion.reminder_days_before');
        $reminderDate = now()->addDays($reminderDays);
        $reminderStart = $reminderDate->copy()->startOfDay();
        $reminderEnd = $reminderDate->copy()->endOfDay();

        User::query()
            ->scheduledForDeletion()
            ->whereBetween('scheduled_deletion_at', [$reminderStart, $reminderEnd])
            ->chunkById(100, function (Collection $users): void {
                $users->each(fn (User $user) => $user->notify(new UserDeletionReminderNotification($user)));
            });

        Workspace::query()
            ->scheduledOnRequest()
            ->whereBetween('scheduled_deletion_at', [$reminderStart, $reminderEnd])
            ->with('owner')
            ->chunkById(100, function (Collection $workspaces): void {
                $workspaces->each(function (Workspace $workspace): void {
                    $owner = $workspace->owner;

                    if (! $owner instanceof User) {
                        return;
                    }

                    $owner->notify(new WorkspaceDeletionReminderNotification($workspace));
                });
            });
    }
}
