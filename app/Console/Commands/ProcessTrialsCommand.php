<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Plan;
use App\Mail\ProTrialEndingSoonMail;
use App\Models\Team;
use App\Models\User;
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

        Team::query()
            ->whereBetween('trial_ends_at', [$windowStart, $windowEnd])
            ->whereDoesntHave('subscriptions', function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->with('owner')
            ->chunkById(100, function (Collection $teams) use (&$count): void {
                $teams->each(function (Team $team) use (&$count): void {
                    /** @var User $owner */
                    $owner = $team->owner;

                    Mail::to($owner->email)->queue(new ProTrialEndingSoonMail($team));
                    $count++;
                });
            });

        $this->comment("Sent {$count} trial-ending reminder(s).");
    }

    private function pauseExpired(CreditService $credits): void
    {
        $count = 0;

        Team::query()
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->chunkById(100, function (Collection $teams) use ($credits, &$count): void {
                $teams->each(function (Team $team) use ($credits, &$count): void {
                    $hasLiveSubscription = $team->subscriptions()
                        ->where(function (Builder $query): void {
                            $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
                        })
                        ->exists();

                    if ($hasLiveSubscription) {
                        $team->forceFill(['trial_ends_at' => null])->save();

                        return;
                    }

                    DB::transaction(function () use ($team, $credits): void {
                        $team->forceFill(['plan' => Plan::Free, 'trial_ends_at' => null])->save();
                        $credits->resetPeriod($team);
                    });

                    $this->info("Trial expired, paused hosted access: {$team->name}");
                    $count++;
                });
            });

        $this->comment("Paused {$count} expired trial(s).");
    }
}
