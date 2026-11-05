<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Commands;

use App\Enums\DeletionReason;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletionWarningMail;
use Relaticle\Cloud\Billing\Queries\InactiveTrialWorkspacesQuery;

#[Description('Schedule deletion of paused trial workspaces nobody has signed in to, and warn their owners')]
#[Signature('billing:schedule-inactive-deletions')]
final class ScheduleInactiveTrialDeletionsCommand extends Command
{
    public const int KEEP_DAYS = 60;

    public const int WARNING_DAYS = 14;

    public function handle(InactiveTrialWorkspacesQuery $inactive): int
    {
        $scheduled = 0;

        $inactive->candidates()->each(function (Workspace $workspace) use ($inactive, &$scheduled): void {
            $owner = $workspace->owner;
            $pausedAt = $workspace->paused_at;

            if (! $owner instanceof User || $pausedAt === null) {
                return;
            }

            $deletesAt = $inactive->lastActivity($workspace)->addDays(self::KEEP_DAYS);
            $earliest = now()->addDays(self::WARNING_DAYS);

            if ($deletesAt->greaterThan($earliest)) {
                return;
            }

            $deletesAt = $deletesAt->max($earliest);

            $this->info("Scheduling deletion of {$workspace->name} for {$deletesAt->toDateString()}...");

            DB::transaction(function () use ($workspace, $owner, $deletesAt, $pausedAt): void {
                $workspace->forceFill([
                    'scheduled_deletion_at' => $deletesAt,
                    'deletion_reason' => DeletionReason::Inactivity,
                ])->save();

                Mail::to($owner->email)->queue(new InactiveWorkspaceDeletionWarningMail($workspace, $deletesAt, $pausedAt));
            });

            $scheduled++;
        });

        $this->comment("Scheduled {$scheduled} inactive workspace(s) for deletion.");

        return self::SUCCESS;
    }
}
