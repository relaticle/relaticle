<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivationStep;
use App\Filament\Pages\Dashboard;
use App\Mail\SetupNudgeMail;
use App\Models\Team;
use App\Models\User;
use App\Services\WorkspaceActivationFacts;
use DateTimeZone;
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

        $this->recipientsAtLocalHour(9)
            ->whereHas('ownedTeams', fn (Builder $query): Builder => $query
                ->where('personal_team', true)
                ->whereNull('setup_nudge_sent_at')
                ->whereNull('scheduled_deletion_at')
                ->whereBetween('created_at', [now()->subDays(3), now()->subDays(2)]))
            ->whereNotNull('email_verified_at')
            ->with('ownedTeams')
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

    /**
     * Users whose local time is currently at the given hour, filtered in the
     * database (indexed on `timezone`) so the hourly run never loads the whole
     * user table, only the ~1/24th of users currently in the 09:00 band.
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

    private function sendForUser(User $user, WorkspaceActivationFacts $facts): bool
    {
        $localNow = Date::now($user->effectiveTimezone());

        if ($localNow->hour !== 9) {
            return false;
        }

        $sent = false;

        foreach ($user->ownedTeams as $team) {
            if (! $team->personal_team
                || $team->setup_nudge_sent_at !== null
                || $team->scheduled_deletion_at !== null
                || ! $team->created_at?->between(now()->subDays(3), now()->subDays(2))) {
                continue;
            }

            if ($this->sendForTeam($user, $team, $facts)) {
                $sent = true;
            }
        }

        return $sent;
    }

    private function sendForTeam(User $user, Team $team, WorkspaceActivationFacts $facts): bool
    {
        if ($facts->hasOwnRecord($team)) {
            return false;
        }

        $stepKey = $this->topUnfinishedStep($team);

        if (! $stepKey instanceof ActivationStep) {
            return false;
        }

        $conversationUrl = $this->continueUrl($team);

        Mail::to($user)
            ->send(new SetupNudgeMail($user, $stepKey->value, $conversationUrl));

        $team->forceFill(['setup_nudge_sent_at' => now()])->save();

        return true;
    }

    private function topUnfinishedStep(Team $team): ?ActivationStep
    {
        $steps = $team->onboarding()->steps();

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
    private function continueUrl(Team $team): string
    {
        return Dashboard::getUrl(['tenant' => $team], panel: 'app');
    }
}
