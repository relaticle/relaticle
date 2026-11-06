<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Commands;

use App\Enums\DeletionReason;
use App\Features\Billing;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Billing\Actions\CancelInactivityDeletion;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletionReminderMail;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletionWarningMail;
use Relaticle\Cloud\Billing\Queries\InactiveTrialWorkspacesQuery;

#[Description('Schedule deletion of paused trial workspaces nobody has signed in to, and warn their owners')]
#[Signature('billing:schedule-inactive-deletions')]
final class ScheduleInactiveTrialDeletionsCommand extends Command
{
    public function handle(InactiveTrialWorkspacesQuery $inactive, CancelInactivityDeletion $cancel): int
    {
        if (Feature::inactive(Billing::class)) {
            $this->comment('Hosted billing is off, so no workspace is deleted for inactivity.');

            return self::SUCCESS;
        }

        $this->scheduleDeletions($inactive);
        $this->sendReminders($inactive, $cancel);

        return self::SUCCESS;
    }

    private function scheduleDeletions(InactiveTrialWorkspacesQuery $inactive): void
    {
        $scheduled = 0;

        $pausedBy = now()->subDays(InactiveTrialWorkspacesQuery::KEEP_DAYS - InactiveTrialWorkspacesQuery::WARNING_DAYS - 1);

        $inactive->candidates($pausedBy)->each(function (Workspace $workspace) use ($inactive, &$scheduled): void {
            $owner = $workspace->owner;

            if (! $owner instanceof User) {
                return;
            }

            $lastActiveAt = $inactive->lastActivity($workspace);
            $deletesAt = $lastActiveAt->addDays(InactiveTrialWorkspacesQuery::KEEP_DAYS);
            $earliest = now()->addDays(InactiveTrialWorkspacesQuery::WARNING_DAYS);

            if ($deletesAt->greaterThan($earliest->addDay())) {
                return;
            }

            $deletesAt = $deletesAt->max($earliest);

            $this->info("Scheduling deletion of {$workspace->name} for {$deletesAt->toDateString()}...");

            $wasScheduled = DB::transaction(function () use ($workspace, $owner, $deletesAt, $lastActiveAt): bool {
                $updated = Workspace::query()
                    ->whereKey($workspace->getKey())
                    ->notScheduledForDeletion()
                    ->whereNotExists($this->signedInSince($lastActiveAt))
                    ->update([
                        'scheduled_deletion_at' => $deletesAt,
                        'deletion_reason' => DeletionReason::Inactivity->value,
                    ]);

                if ($updated !== 1) {
                    return false;
                }

                Mail::to($owner->email)->queue(new InactiveWorkspaceDeletionWarningMail($workspace, $deletesAt, $lastActiveAt));

                return true;
            });

            if ($wasScheduled) {
                $scheduled++;
            }
        });

        $this->comment("Scheduled {$scheduled} inactive workspace(s) for deletion.");
    }

    private function signedInSince(CarbonImmutable $lastActiveAt): QueryBuilder
    {
        return User::query()
            ->toBase()
            ->selectRaw('1')
            ->where('last_login_at', '>', $lastActiveAt)
            ->where(fn (QueryBuilder $users): QueryBuilder => $users
                ->whereColumn('users.id', 'workspaces.user_id')
                ->orWhereIn('users.id', Membership::query()->toBase()->select('user_id')->whereColumn('workspace_id', 'workspaces.id')));
    }

    private function sendReminders(InactiveTrialWorkspacesQuery $inactive, CancelInactivityDeletion $cancel): void
    {
        $reminderDate = now()->addDays(InactiveTrialWorkspacesQuery::REMINDER_DAYS);
        $reminded = 0;

        Workspace::query()
            ->scheduledForInactivity()
            ->whereBetween('scheduled_deletion_at', [$reminderDate->startOfDay(), $reminderDate->endOfDay()])
            ->with('owner')
            ->chunkById(100, function (Collection $workspaces) use ($inactive, $cancel, &$reminded): void {
                $workspaces->each(function (Workspace $workspace) use ($inactive, $cancel, &$reminded): void {
                    $owner = $workspace->owner;

                    if (! $owner instanceof User || $workspace->scheduled_deletion_at === null) {
                        return;
                    }

                    if (! $inactive->stillDue($workspace)) {
                        $this->info("Cancelling the deletion of {$workspace->name}, which no longer stands.");
                        $cancel->execute($workspace);

                        return;
                    }

                    $this->info("Reminding the owner of {$workspace->name}...");

                    Mail::to($owner->email)->queue(new InactiveWorkspaceDeletionReminderMail($workspace, $workspace->scheduled_deletion_at));
                    $reminded++;
                });
            });

        $this->comment("Sent {$reminded} deletion reminder(s).");
    }
}
