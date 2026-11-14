<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use App\Support\CurrentWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\AutomatedReason;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\AutomatedMailDetector;

#[Description('Store why stored inbound mail is automated, from its sender and its Marketing label')]
#[Signature('email:backfill-automated-reasons
    {--force : Write reasons instead of reporting them}
    {--before= : Only mail stored before this time, required with --force}
    {--after= : Only emails with an id above this one, the last a queued run read}
    {--workspace= : Only this workspace}
    {--chunk=10000 : Emails to read in one run before queueing the rest}')]
final class BackfillAutomatedReasonsCommand extends Command
{
    private const int PAGE = 500;

    public function handle(AutomatedMailDetector $detector, RefreshConversationSummary $refresh, CurrentWorkspace $currentWorkspace): int
    {
        $write = (bool) $this->option('force');

        if ($write && blank($this->option('before'))) {
            $this->error('--force needs --before, so a rerun never reads mail stored after the first run.');

            return self::FAILURE;
        }

        $budget = $write ? max(1, (int) $this->option('chunk')) : PHP_INT_MAX;
        [$marked, $read, $lastId] = [0, 0, null];
        /** @var array<string, array<string, true>> $touched */
        $touched = [];

        foreach ($this->candidates()->lazyById(self::PAGE) as $email) {
            // Per page, so a killed run leaves at most one page of stale summaries for the
            // conversation backfill queued at the end to repair.
            if ($write && $read > 0 && $read % self::PAGE === 0) {
                $this->refreshSummaries($touched, $refresh, $currentWorkspace);
                $touched = [];
            }

            if ($read === $budget) {
                $this->refreshSummaries($touched, $refresh, $currentWorkspace);

                return $this->queueRest((string) $lastId, "{$marked} email(s) marked.");
            }

            [$read, $lastId] = [$read + 1, $email->getKey()];
            $reason = $detector->detectStored($email, $email->labels->contains('label', EmailCategory::Marketing->value));

            if (! $reason instanceof AutomatedReason || ($write && ! $this->mark($email, $reason))) {
                continue;
            }

            $marked++;

            if ($write && $email->conversation_key !== null) {
                $touched[$email->workspace_id][$email->conversation_key] = true;
            }
        }

        if (! $write) {
            $this->comment("{$marked} email(s) would be marked. Re-run with --force to write them.");

            return self::SUCCESS;
        }

        $this->refreshSummaries($touched, $refresh, $currentWorkspace);
        Artisan::queue('email:backfill-conversations', array_filter([
            '--force' => true,
            '--workspace' => $this->option('workspace'),
        ]))->onQueue('imports');
        $this->comment("{$marked} email(s) marked. A conversation summary check is queued.");

        return self::SUCCESS;
    }

    /** @return Builder<Email> */
    private function candidates(): Builder
    {
        return Email::query()->withoutGlobalScopes()
            ->with(['participants', 'labels' => fn (HasMany $query): HasMany => $query->where('source', 'system')])
            ->whereNull('automated_reason')
            ->where('direction', EmailDirection::INBOUND)
            ->where('status', '!=', EmailStatus::DRAFT)
            ->where('created_at', '<', Date::parse((string) ($this->option('before') ?? now()->toIso8601String()))->utc())
            ->when($this->option('after'), fn (Builder $query, string $id): Builder => $query->where('id', '>', $id))
            ->when($this->option('workspace'), fn (Builder $query, string $id): Builder => $query->where('workspace_id', $id));
    }

    private function mark(Email $email, AutomatedReason $reason): bool
    {
        // Guarded, so a reason sync wrote since the read stays, and updated_at keeps meaning a user edit.
        return Email::query()->withoutGlobalScopes()
            ->whereKey($email->getKey())
            ->whereNull('automated_reason')
            ->toBase()
            ->update(['automated_reason' => $reason->value]) === 1;
    }

    /** @param  array<string, array<string, true>>  $touched */
    private function refreshSummaries(array $touched, RefreshConversationSummary $refresh, CurrentWorkspace $currentWorkspace): void
    {
        foreach ($touched as $workspaceId => $keys) {
            $currentWorkspace->within($workspaceId, function () use ($workspaceId, $keys, $refresh): void {
                foreach (array_keys($keys) as $conversationKey) {
                    $refresh->execute($workspaceId, $conversationKey, broadcast: false);
                }
            });
        }
    }

    private function queueRest(string $after, string $progress): int
    {
        Artisan::queue('email:backfill-automated-reasons', array_filter([
            '--force' => true,
            '--before' => $this->option('before'),
            '--after' => $after,
            '--workspace' => $this->option('workspace'),
            '--chunk' => $this->option('chunk'),
        ]))->onQueue('imports')->delay(now()->addMinute());

        $this->comment("{$progress} The rest is queued.");

        return self::SUCCESS;
    }
}
