<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Commands;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Relaticle\Cloud\Billing\Mail\InactivityRuleNoticeMail;
use Relaticle\Cloud\Billing\Queries\InactiveTrialWorkspacesQuery;

#[Description('Tell the owners of paused trial workspaces about the 60-day rule, once')]
#[Signature('billing:announce-inactivity-rule {--force : Send the notices. Without it the command only reports}')]
final class AnnounceInactivityRuleCommand extends Command
{
    public function handle(InactiveTrialWorkspacesQuery $inactive): int
    {
        $force = (bool) $this->option('force');
        $count = 0;

        $inactive->candidates()
            ->filter(static fn (Workspace $workspace): bool => $workspace->inactivity_notice_sent_at === null)
            ->each(function (Workspace $workspace) use ($inactive, $force, &$count): void {
                $owner = $workspace->owner;

                if (! $owner instanceof User) {
                    return;
                }

                if (! $force) {
                    $this->info("Would notify the owner of {$workspace->name}.");
                    $count++;

                    return;
                }

                $this->info("Notifying the owner of {$workspace->name}...");

                $deletesAt = $inactive->lastActivity($workspace)->addDays(ScheduleInactiveTrialDeletionsCommand::KEEP_DAYS);

                $notified = DB::transaction(function () use ($workspace, $owner, $deletesAt): bool {
                    $updated = Workspace::query()
                        ->whereKey($workspace->getKey())
                        ->whereNull('inactivity_notice_sent_at')
                        ->notScheduledForDeletion()
                        ->update(['inactivity_notice_sent_at' => now()]);

                    if ($updated !== 1) {
                        return false;
                    }

                    Mail::to($owner->email)->queue(new InactivityRuleNoticeMail($workspace, $deletesAt));

                    return true;
                });

                if ($notified) {
                    $count++;
                }
            });

        $this->comment($force ? "Notified {$count} owner(s)." : "{$count} owner(s) would be notified. Run with --force to send.");

        return self::SUCCESS;
    }
}
