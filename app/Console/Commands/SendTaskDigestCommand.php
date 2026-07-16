<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Features\TaskDigestEmails;
use App\Mail\TaskDigestMail;
use App\Models\User;
use App\Services\Notifications\DigestService;
use DateTimeZone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

#[Description('Send daily task digest emails to users whose local time is 08:00')]
#[Signature('notifications:send-task-digest')]
final class SendTaskDigestCommand extends Command
{
    public function handle(DigestService $digestService): int
    {
        $sent = 0;

        $this->recipientsAtLocalHour(8)
            ->with(['ownedTeams', 'teams'])
            ->chunkById(500, function (Collection $users) use ($digestService, &$sent): void {
                foreach ($users as $user) {
                    if ($this->sendForUser($user, $digestService)) {
                        $sent++;
                    }
                }
            });

        $this->info("Queued {$sent} task digest email(s).");

        return self::SUCCESS;
    }

    /**
     * Users whose local time is currently at the given hour, filtered in the
     * database (indexed on `timezone`) so the hourly run never loads the whole
     * user table — only the ~1/24th of users currently in the 08:00 band.
     *
     * @return Builder<User>
     */
    private function recipientsAtLocalHour(int $hour): Builder
    {
        $timezones = $this->timezonesAtLocalHour($hour);
        $appTimezoneMatches = in_array((string) config('app.timezone'), $timezones, true);

        return User::query()->where(function (Builder $query) use ($timezones, $appTimezoneMatches): void {
            $query->whereIn('timezone', $timezones);

            if ($appTimezoneMatches) {
                $query->orWhereNull('timezone');
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function timezonesAtLocalHour(int $hour): array
    {
        return array_values(array_filter(
            DateTimeZone::listIdentifiers(),
            fn (string $timezone): bool => (int) Date::now($timezone)->format('G') === $hour,
        ));
    }

    private function sendForUser(User $user, DigestService $digestService): bool
    {
        $timezone = $user->timezone ?? (string) config('app.timezone');
        $localNow = Date::now($timezone);

        if ($localNow->hour !== 8) {
            return false;
        }

        if (! $user->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email)) {
            return false;
        }

        if (! Feature::for($user)->active(TaskDigestEmails::class)) {
            return false;
        }

        $payload = $digestService->forUser($user);

        if ($payload->isEmpty()) {
            return false;
        }

        Mail::mailer('postmark_broadcast')
            ->to($user)
            ->send(new TaskDigestMail($user, $payload));

        return true;
    }
}
