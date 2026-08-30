<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\User;
use App\Support\Email\SubscriberProfileDeriver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * The convergence backstop: re-derives every verified user's subscriber
 * profile and syncs the ones that drifted, whatever the cause (a missed
 * event, a failed job, recency decay, a deleted team).
 */
#[Description('Sync Mailcoach subscriber profiles for verified users whose derived profile changed')]
#[Signature('subscribers:reconcile
    {--dry-run : List the users that would sync without dispatching jobs}
    {--limit= : Cap the number of users synced this run}')]
final class ReconcileSubscribersCommand extends Command
{
    public function handle(SubscriberProfileDeriver $deriver): int
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            $this->info('Mailcoach subscriber sync is disabled.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');
        $changed = 0;

        User::query()
            ->whereNotNull('email_verified_at')
            ->with(['ownedTeams', 'teams'])
            ->chunkById(200, function (Collection $users) use ($deriver, $dryRun, $limit, &$changed): bool {
                /** @var User $user */
                foreach ($users as $user) {
                    if ($limit !== null && $changed >= $limit) {
                        return false;
                    }

                    $profile = $deriver->derive($user);

                    if ($profile->matchesStored($user)) {
                        continue;
                    }

                    $changed++;

                    if ($dryRun) {
                        $this->line("Would sync {$profile->email}: ".implode(', ', $profile->tags));

                        continue;
                    }

                    SyncSubscriberJob::dispatchFor((string) $user->id);
                }

                return true;
            });

        $this->info($dryRun ? "Would sync {$changed} users." : "Dispatched {$changed} sync jobs.");

        return self::SUCCESS;
    }
}
