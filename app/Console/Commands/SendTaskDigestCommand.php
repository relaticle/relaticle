<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Features\TaskDigestEmails;
use App\Mail\TaskDigestMail;
use App\Models\User;
use App\Services\Notifications\DigestService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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

        User::query()->with(['ownedTeams', 'teams'])->chunkById(500, function (Collection $users) use ($digestService, &$sent): void {
            foreach ($users as $user) {
                if ($this->sendForUser($user, $digestService)) {
                    $sent++;
                }
            }
        });

        $this->info("Queued {$sent} task digest email(s).");

        return self::SUCCESS;
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
