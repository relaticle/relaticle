<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivationStep;
use App\Filament\Pages\Dashboard;
use App\Mail\SetupNudgeMail;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceActivationFacts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Spatie\Onboard\OnboardingStep;

#[Description('Send a day-2 setup nudge email to owners of empty personal workspaces whose local time is 09:00')]
#[Signature('notifications:send-setup-nudge')]
final class SendSetupNudgeCommand extends Command
{
    public function handle(WorkspaceActivationFacts $facts): int
    {
        $sent = 0;

        User::query()
            ->atLocalHour(9)
            ->whereHas('ownedWorkspaces', fn (Builder $query): Builder => $query
                ->where('personal_workspace', true)
                ->whereNull('setup_nudge_sent_at')
                ->whereNull('scheduled_deletion_at')
                ->whereBetween('created_at', [now()->subDays(3), now()->subDays(2)]))
            ->whereNotNull('email_verified_at')
            ->with('ownedWorkspaces')
            ->chunkById(500, function (Collection $users) use ($facts, &$sent): void {
                foreach ($users as $user) {
                    $this->info("Checking user id `{$user->getKey()}`...");

                    if ($this->sendForUser($user, $facts)) {
                        $sent++;
                    }
                }
            });

        $this->comment("Queued {$sent} setup nudge email(s).");

        return self::SUCCESS;
    }

    private function sendForUser(User $user, WorkspaceActivationFacts $facts): bool
    {
        $localNow = Date::now($user->effectiveTimezone());

        if ($localNow->hour !== 9) {
            return false;
        }

        $sent = false;

        foreach ($user->ownedWorkspaces as $workspace) {
            if (! $workspace->personal_workspace
                || $workspace->setup_nudge_sent_at !== null
                || $workspace->scheduled_deletion_at !== null
                || ! $workspace->created_at?->between(now()->subDays(3), now()->subDays(2))) {
                continue;
            }

            if ($this->sendForWorkspace($user, $workspace, $facts)) {
                $sent = true;
            }
        }

        return $sent;
    }

    private function sendForWorkspace(User $user, Workspace $workspace, WorkspaceActivationFacts $facts): bool
    {
        if ($facts->hasOwnRecord($workspace)) {
            return false;
        }

        $stepKey = $this->topUnfinishedStep($workspace);

        if (! $stepKey instanceof ActivationStep) {
            return false;
        }

        $conversationUrl = $this->continueUrl($workspace);

        Mail::to($user)
            ->send(new SetupNudgeMail($user, $workspace, $stepKey->value, $conversationUrl));

        $workspace->forceFill(['setup_nudge_sent_at' => now()])->save();

        return true;
    }

    private function topUnfinishedStep(Workspace $workspace): ?ActivationStep
    {
        $steps = $workspace->onboarding()->steps();

        foreach ([ActivationStep::FirstRecord, ActivationStep::Import, ActivationStep::Invite] as $candidate) {
            $step = $steps->first(function (OnboardingStep $step) use ($candidate): bool {
                $key = $step->attribute('key');

                return ($key instanceof ActivationStep ? $key : ActivationStep::from((string) $key)) === $candidate;
            });

            if ($step instanceof OnboardingStep && $step->incomplete()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Where "Continue in Rela" lands. An id-less chat URL is not a destination:
     * that page bounces straight back to the dashboard, so the nudge points at
     * the dashboard composer directly.
     */
    private function continueUrl(Workspace $workspace): string
    {
        return Dashboard::getUrl(['tenant' => $workspace], panel: 'app');
    }
}
