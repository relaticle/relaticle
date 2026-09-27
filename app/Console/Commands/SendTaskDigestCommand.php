<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Mail\TaskDigestMail;
use App\Models\User;
use App\Services\Notifications\DigestService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;

#[Description('Send daily task digest emails to users whose local time is 08:00')]
#[Signature('notifications:send-task-digest')]
final class SendTaskDigestCommand extends Command
{
    private const int ACTIVE_WITHIN_DAYS = 30;

    public function handle(DigestService $digestService): int
    {
        $sent = 0;

        User::query()
            ->atLocalHour(8)
            ->where('last_login_at', '>=', now()->subDays(self::ACTIVE_WITHIN_DAYS))
            ->with(['ownedWorkspaces', 'workspaces'])
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

    private function sendForUser(User $user, DigestService $digestService): bool
    {
        $localNow = Date::now($user->effectiveTimezone());

        if ($localNow->hour !== 8) {
            return false;
        }

        if (! $user->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email)) {
            return false;
        }

        $payload = $digestService->forUser($user);

        if ($payload->isEmpty()) {
            return false;
        }

        Mail::to($user)
            ->send(new TaskDigestMail($user, $payload));

        return true;
    }
}
