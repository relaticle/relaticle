<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\UserDeletionReminderNotification;
use App\Notifications\WorkspaceDeletionReminderNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

#[Description('Permanently delete users and workspaces past their scheduled deletion date, and send reminders ahead of purge')]
#[Signature('app:purge-scheduled-deletions')]
final class PurgeScheduledDeletionsCommand extends Command
{
    public function handle(DeletesUsers $deletesUsers, DeletesTeams $deletesWorkspaces): int
    {
        $this->purgeExpiredUsers($deletesUsers);
        $this->purgeExpiredWorkspaces($deletesWorkspaces);
        $this->sendReminders();

        return self::SUCCESS;
    }

    private function purgeExpiredUsers(DeletesUsers $deletesUsers): void
    {
        $count = 0;

        User::query()
            ->expiredDeletion()
            ->chunkById(100, function (Collection $users) use ($deletesUsers, &$count): void {
                $users->each(function (User $user) use ($deletesUsers, &$count): void {
                    DB::transaction(fn () => $deletesUsers->delete($user));

                    Log::info('Purged user account', ['user_id' => $user->id, 'email' => $user->email]);
                    $this->info("Purged user: {$user->email}");
                    $count++;
                });
            });

        $this->info("Purged {$count} user(s).");
    }

    private function purgeExpiredWorkspaces(DeletesTeams $deletesWorkspaces): void
    {
        $count = 0;

        Workspace::query()
            ->expiredDeletion()
            ->chunkById(100, function (Collection $workspaces) use ($deletesWorkspaces, &$count): void {
                $workspaces->each(function (Workspace $workspace) use ($deletesWorkspaces, &$count): void {
                    DB::transaction(fn () => $deletesWorkspaces->delete($workspace));

                    Log::info('Purged workspace', ['workspace_id' => $workspace->id, 'name' => $workspace->name]);
                    $this->info("Purged workspace: {$workspace->name}");
                    $count++;
                });
            });

        $this->info("Purged {$count} workspace(s).");
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
            ->scheduledForDeletion()
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
