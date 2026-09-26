<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;
use Throwable;

final readonly class RetryMailboxHistoryImportFailuresAction
{
    public function __construct(
        private MailboxHistoryImportService $mailboxHistoryImport,
    ) {}

    public function execute(User $user, ConnectedAccount $account, string $batchId): bool
    {
        abort_unless($account->user_id === $user->getKey()
            && $user->belongsToWorkspace($account->workspace), 403);

        $acquired = false;
        $queued = Cache::lock('retry-mailbox-history:'.$batchId, 60)->get(function () use ($account, $batchId, &$acquired): bool {
            $acquired = true;
            $account->refresh();
            $batch = Bus::findBatch($batchId);

            if ($account->history_import_batch_id !== $batchId || $account->sync_cursor === null
                || ! $batch instanceof Batch || $batch->cancelled()) {
                return false;
            }

            if ($account->status === EmailAccountStatus::REAUTH_REQUIRED) {
                return false;
            }

            $retryableUuids = $this->retryableFailedJobUuids($batch, $batchId);
            $needsCalendar = $this->needsCalendarRecovery($account, $batchId);

            if ($retryableUuids === [] && ! $needsCalendar) {
                return $this->recoveryAlreadyQueued($batch, $batchId);
            }

            $alreadyAwaiting = $this->mailboxHistoryImport->hasAwaitingRetrySuccessNotice($batchId);
            $this->mailboxHistoryImport->markAwaitingRetrySuccessNotice($batchId);

            $queued = false;

            try {
                foreach ($retryableUuids as $failedJobUuid) {
                    Artisan::call('queue:retry', ['id' => $failedJobUuid]);
                }

                $remainingUuids = DB::table('failed_jobs')
                    ->whereIn('uuid', $retryableUuids)
                    ->pluck('uuid')
                    ->map(static fn (mixed $uuid): string => (string) $uuid)
                    ->all();

                if (array_diff($retryableUuids, $remainingUuids) !== []) {
                    $queued = true;
                }

                if ($needsCalendar && $this->queueCalendarRecovery($account, $batchId)) {
                    $queued = true;
                }
            } catch (Throwable) {
                if (! $alreadyAwaiting) {
                    $this->mailboxHistoryImport->clearAwaitingRetrySuccessNotice($batchId);
                }

                return false;
            }

            if (! $queued) {
                if (! $alreadyAwaiting) {
                    $this->mailboxHistoryImport->clearAwaitingRetrySuccessNotice($batchId);
                }

                return $this->recoveryAlreadyQueued($batch, $batchId);
            }

            $account = $account->fresh() ?? $account;

            if ($this->mailboxHistoryImport->calendarFailureCount($batchId) === 0
                && ($account->calendar_sync_cursor !== null || ! $account->hasCalendar())) {
                $account->update(['last_error' => null]);
            }

            return true;
        });

        return $acquired ? (bool) $queued : true;
    }

    /**
     * @return list<string>
     */
    private function retryableFailedJobUuids(Batch $batch, string $batchId): array
    {
        $failedJobUuids = $this->resolveFailedJobUuids($batch, $batchId);

        if ($failedJobUuids === []) {
            return [];
        }

        return array_values(DB::table('failed_jobs')
            ->whereIn('uuid', $failedJobUuids)
            ->pluck('uuid')
            ->map(static fn (mixed $uuid): string => (string) $uuid)
            ->all());
    }

    private function needsCalendarRecovery(ConnectedAccount $account, string $batchId): bool
    {
        if (! $account->hasCalendar()) {
            return false;
        }

        if ($this->mailboxHistoryImport->calendarFailureCount($batchId) > 0) {
            return true;
        }

        return $account->calendar_sync_cursor === null;
    }

    private function recoveryAlreadyQueued(Batch $batch, string $batchId): bool
    {
        if ($this->mailboxHistoryImport->isCalendarImportPending($batchId)) {
            return true;
        }

        return $batch->pendingJobs > count($batch->failedJobIds);
    }

    private function queueCalendarRecovery(ConnectedAccount $account, string $batchId): bool
    {
        $this->mailboxHistoryImport->markCalendarImportPending($batchId);
        MailboxSyncTracker::markCalendarStarted($account);

        $account->update([
            'status' => EmailAccountStatus::ACTIVE,
            'calendar_sync_cursor' => null,
        ]);

        dispatch(new InitialCalendarSyncJob($account, reconcileAfter: true));

        return true;
    }

    /**
     * @return list<string>
     */
    private function resolveFailedJobUuids(Batch $batch, string $batchId): array
    {
        if ($batch->failedJobIds !== []) {
            return array_values($batch->failedJobIds);
        }

        $storeJobName = class_basename(StoreEmailJob::class);
        $uuids = [];

        foreach (DB::table('failed_jobs')->where('queue', 'emails-sync')->get(['uuid', 'payload']) as $row) {
            $payload = json_decode((string) $row->payload, true);

            if (! is_array($payload)) {
                continue;
            }

            $displayName = $payload['displayName'] ?? '';

            if (! is_string($displayName) || ! str_contains($displayName, $storeJobName)) {
                continue;
            }

            $command = $payload['data']['command'] ?? '';

            if (! is_string($command) || ! str_contains($command, $batchId)) {
                continue;
            }

            $uuids[] = (string) $row->uuid;
        }

        return array_values(array_unique($uuids));
    }
}
