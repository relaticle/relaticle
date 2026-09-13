<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Plan;
use App\Mail\ProTrialEndingSoonMail;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Relaticle\Chat\Services\CreditService;

#[Description('Send trial-ending reminders and pause expired Pro trials')]
#[Signature('billing:process-trials')]
final class ProcessTrialsCommand extends Command
{
    public function handle(CreditService $credits): int
    {
        $this->sendEndingSoonReminders();
        $this->pauseExpired($credits);

        return self::SUCCESS;
    }

    private function sendEndingSoonReminders(): void
    {
        $windowStart = now()->addDays(3)->startOfDay();
        $windowEnd = now()->addDays(3)->endOfDay();
        $count = 0;

        Workspace::query()
            ->whereBetween('trial_ends_at', [$windowStart, $windowEnd])
            ->whereDoesntHave('subscriptions', function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->with('owner')
            ->chunkById(100, function (Collection $workspaces) use (&$count): void {
                $workspaces->each(function (Workspace $workspace) use (&$count): void {
                    $owner = $workspace->owner;

                    if (! $owner instanceof User) {
                        return;
                    }

                    Mail::to($owner->email)->queue(new ProTrialEndingSoonMail($workspace));
                    $count++;
                });
            });

        $this->comment("Sent {$count} trial-ending reminder(s).");
    }

    private function pauseExpired(CreditService $credits): void
    {
        $count = 0;

        Workspace::query()
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->with('subscriptions')
            ->chunkById(100, function (Collection $workspaces) use ($credits, &$count): void {
                $workspaces->each(function (Workspace $workspace) use ($credits, &$count): void {
                    $hasLiveSubscription = $workspace->subscriptions()
                        ->where(function (Builder $query): void {
                            $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
                        })
                        ->exists();

                    // Only revert what the trial granted. A converted
                    // subscription, or a plan assigned outside the trial (e.g. a
                    // sysadmin Enterprise grant), outlives the trial window, so
                    // clear the stale timestamp so it is not reprocessed daily.
                    if ($hasLiveSubscription || $workspace->plan !== Plan::Pro) {
                        $workspace->forceFill(['trial_ends_at' => null])->save();

                        return;
                    }

                    DB::transaction(function () use ($workspace, $credits): void {
                        $workspace->forceFill(['plan' => Plan::Free, 'trial_ends_at' => null])->save();
                        $credits->resetPeriod($workspace);
                    });

                    $this->info("Trial expired, paused hosted access: {$workspace->name}");
                    $count++;
                });
            });

        $this->comment("Paused {$count} expired trial(s).");
    }
}
