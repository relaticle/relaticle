<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use App\Notifications\TeamDeletionReminderNotification;
use App\Notifications\UserDeletionReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

final class PurgeScheduledDeletionsCommand extends Command
{
    protected $signature = 'app:purge-scheduled-deletions';

    protected $description = 'Permanently delete users and teams past their scheduled deletion date, and send reminders ahead of purge';

    public function handle(DeletesUsers $deletesUsers, DeletesTeams $deletesTeams): int
    {
        $this->purgeExpiredUsers($deletesUsers);
        $this->purgeExpiredTeams($deletesTeams);
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

    private function purgeExpiredTeams(DeletesTeams $deletesTeams): void
    {
        $count = 0;

        Team::query()
            ->expiredDeletion()
            ->chunkById(100, function (Collection $teams) use ($deletesTeams, &$count): void {
                $teams->each(function (Team $team) use ($deletesTeams, &$count): void {
                    DB::transaction(fn () => $deletesTeams->delete($team));

                    Log::info('Purged team', ['team_id' => $team->id, 'name' => $team->name]);
                    $this->info("Purged team: {$team->name}");
                    $count++;
                });
            });

        $this->info("Purged {$count} team(s).");
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

        Team::query()
            ->scheduledForDeletion()
            ->whereBetween('scheduled_deletion_at', [$reminderStart, $reminderEnd])
            ->with('owner')
            ->chunkById(100, function (Collection $teams): void {
                $teams->each(function (Team $team): void {
                    /** @var User $owner */
                    $owner = $team->owner;

                    $owner->notify(new TeamDeletionReminderNotification($team));
                });
            });
    }
}
