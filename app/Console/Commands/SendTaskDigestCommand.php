<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Mail\TaskDigestMail;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\DigestService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
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
            ->with(['ownedWorkspaces.subscriptions', 'workspaces.subscriptions'])
            ->chunkById(500, function (Collection $users) use ($digestService, &$sent): void {
                foreach ($users as $user) {
                    $sent += $this->sendForUser($user, $digestService);
                }
            });

        $this->info("Queued {$sent} task digest email(s).");

        return self::SUCCESS;
    }

    private function sendForUser(User $user, DigestService $digestService): int
    {
        if (Date::now($user->effectiveTimezone())->hour !== 8) {
            return 0;
        }

        if (! $user->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email)) {
            return 0;
        }

        $sent = 0;

        foreach ($user->allWorkspaces() as $workspace) {
            $sent += (int) rescue(fn (): bool => $this->sendForWorkspace($user, $workspace, $digestService), false);
        }

        return $sent;
    }

    private function sendForWorkspace(User $user, Workspace $workspace, DigestService $digestService): bool
    {
        $digest = $digestService->forWorkspace($user, $workspace);

        if ($digest->isEmpty() || ! $this->claimToday($user, $workspace)) {
            return false;
        }

        Mail::to($user)->send(new TaskDigestMail($user, $workspace, $digest));

        return true;
    }

    // The schedule runs once an hour, but a manual or overlapping second run would mail everyone again.
    private function claimToday(User $user, Workspace $workspace): bool
    {
        $localDate = Date::now($user->effectiveTimezone())->toDateString();

        return Cache::add("task-digest:{$user->getKey()}:{$workspace->getKey()}:{$localDate}", true, Date::now()->addDay());
    }
}
