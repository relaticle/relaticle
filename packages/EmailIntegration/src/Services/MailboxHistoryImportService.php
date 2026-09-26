<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Actions\CompleteMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Data\MailboxHistoryImportSummary;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class MailboxHistoryImportService
{
    private const string AWAITING_RETRY_SUCCESS_NOTICE_PREFIX = 'email-integration:history-import-awaiting-retry-success-notice:';

    private const string FAILURE_GENERATION_PREFIX = 'email-integration:history-import-failure-generation:';

    private const string CALENDAR_FAILURES_PREFIX = 'email-integration:history-import-calendar-failures:';

    private const string EMAIL_LISTING_PENDING_PREFIX = 'email-integration:history-import-email-listing:';

    private const string CALENDAR_IMPORT_PENDING_PREFIX = 'email-integration:history-import-calendar-pending:';

    private const string CALENDAR_DISCOVERED_PREFIX = 'email-integration:history-import-calendar-discovered:';

    private const string PROGRESS_HIGH_WATER_PREFIX = 'email-integration:history-import-progress-high-water:';

    public function markAwaitingRetrySuccessNotice(string $batchId): void
    {
        Cache::put(self::AWAITING_RETRY_SUCCESS_NOTICE_PREFIX.$batchId, true, now()->addWeek());
    }

    public function pullAwaitingRetrySuccessNotice(string $batchId): bool
    {
        return (bool) Cache::pull(self::AWAITING_RETRY_SUCCESS_NOTICE_PREFIX.$batchId);
    }

    public function hasAwaitingRetrySuccessNotice(string $batchId): bool
    {
        return Cache::has(self::AWAITING_RETRY_SUCCESS_NOTICE_PREFIX.$batchId);
    }

    public function clearAwaitingRetrySuccessNotice(string $batchId): void
    {
        Cache::forget(self::AWAITING_RETRY_SUCCESS_NOTICE_PREFIX.$batchId);
    }

    public function failureGeneration(string $batchId): int
    {
        return (int) Cache::get(self::FAILURE_GENERATION_PREFIX.$batchId, 0);
    }

    public function recordFailureGeneration(string $batchId): int
    {
        $key = self::FAILURE_GENERATION_PREFIX.$batchId;

        if (! Cache::has($key)) {
            Cache::put($key, 1, now()->addMonth());

            return 1;
        }

        return (int) Cache::increment($key);
    }

    public function recordCalendarFailures(string $batchId, int $count): void
    {
        $key = self::CALENDAR_FAILURES_PREFIX.$batchId;

        if ($count <= 0) {
            Cache::forget($key);

            return;
        }

        Cache::put($key, $count, now()->addWeek());
    }

    public function addCalendarFailures(string $batchId, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $key = self::CALENDAR_FAILURES_PREFIX.$batchId;
        $current = max(0, (int) Cache::get($key, 0));

        Cache::put($key, $current + $count, now()->addWeek());
    }

    public function calendarFailureCount(string $batchId): int
    {
        return max(0, (int) Cache::get(self::CALENDAR_FAILURES_PREFIX.$batchId, 0));
    }

    public function clearCalendarFailures(string $batchId): void
    {
        Cache::forget(self::CALENDAR_FAILURES_PREFIX.$batchId);
    }

    public function markEmailListingStarted(ConnectedAccount $account): void
    {
        Cache::put(self::EMAIL_LISTING_PENDING_PREFIX.$account->getKey(), true, now()->addMonth());
    }

    public function markEmailListingFinished(ConnectedAccount $account): void
    {
        Cache::forget(self::EMAIL_LISTING_PENDING_PREFIX.$account->getKey());
    }

    public function isEmailListingInProgress(ConnectedAccount $account): bool
    {
        return Cache::has(self::EMAIL_LISTING_PENDING_PREFIX.$account->getKey());
    }

    public function markCalendarImportPending(string $batchId): void
    {
        Cache::put(self::CALENDAR_IMPORT_PENDING_PREFIX.$batchId, true, now()->addMonth());
        Cache::forget(self::CALENDAR_DISCOVERED_PREFIX.$batchId);
        Cache::forget(self::PROGRESS_HIGH_WATER_PREFIX.$batchId);
    }

    public function markCalendarImportFinished(string $batchId): void
    {
        Cache::forget(self::CALENDAR_IMPORT_PENDING_PREFIX.$batchId);
        Cache::forget(self::CALENDAR_DISCOVERED_PREFIX.$batchId);
        Cache::forget(self::PROGRESS_HIGH_WATER_PREFIX.$batchId);
    }

    public function isCalendarImportPending(string $batchId): bool
    {
        return Cache::has(self::CALENDAR_IMPORT_PENDING_PREFIX.$batchId);
    }

    public function addCalendarDiscovered(string $batchId, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $key = self::CALENDAR_DISCOVERED_PREFIX.$batchId;
        $current = max(0, (int) Cache::get($key, 0));

        Cache::put($key, $current + $count, now()->addMonth());
    }

    public function calendarDiscoveredCount(string $batchId): int
    {
        return max(0, (int) Cache::get(self::CALENDAR_DISCOVERED_PREFIX.$batchId, 0));
    }

    public function touchCalendarImport(ConnectedAccount $account): void
    {
        MailboxSyncTracker::markCalendarStarted($account);

        $batchId = $account->history_import_batch_id;

        if (! is_string($batchId) || $batchId === '') {
            return;
        }

        Cache::put(self::CALENDAR_IMPORT_PENDING_PREFIX.$batchId, true, now()->addMonth());
    }

    public function completeCalendarImport(ConnectedAccount $account, bool $succeeded): void
    {
        MailboxSyncTracker::markCalendarFinished($account);

        $batchId = $account->history_import_batch_id;

        if (! is_string($batchId) || $batchId === '') {
            return;
        }

        $this->markCalendarImportFinished($batchId);

        if ($succeeded) {
            $this->clearCalendarFailures($batchId);
        }
    }

    public function failCalendarImport(ConnectedAccount $account, int $failedCount = 1): void
    {
        $batchId = $account->history_import_batch_id;

        if (is_string($batchId) && $batchId !== '') {
            $this->recordCalendarFailures($batchId, max(1, $failedCount));
        }

        $this->completeCalendarImport($account, succeeded: false);
    }

    public function historyImportHasUnresolvedEmailFailures(ConnectedAccount $account): bool
    {
        $batchId = $account->history_import_batch_id;

        if (! is_string($batchId) || $batchId === '') {
            return false;
        }

        $batch = Bus::findBatch($batchId);

        return $batch instanceof Batch && $this->batchFailedJobCount($batch) > 0;
    }

    public function lockKey(ConnectedAccount $account): string
    {
        return 'email-history-import:'.$account->getKey();
    }

    public function isRunning(ConnectedAccount $account): bool
    {
        if (! $account->hasEmail() || blank($account->history_import_batch_id)) {
            return false;
        }

        if ($account->sync_cursor === null && $this->isEmailListingInProgress($account)) {
            return true;
        }

        $batch = Bus::findBatch($account->history_import_batch_id);

        if (! $batch instanceof Batch || $batch->cancelled()) {
            return false;
        }

        if ($batch->totalJobs > 0 && ! $this->batchIsComplete($batch)) {
            return true;
        }

        if ($account->sync_cursor !== null) {
            return false;
        }

        return $account->status === EmailAccountStatus::ACTIVE
            && ! $this->batchIsComplete($batch);
    }

    public function summary(ConnectedAccount $account): ?MailboxHistoryImportSummary
    {
        if (blank($account->history_import_batch_id)) {
            return null;
        }

        $batch = Bus::findBatch($account->history_import_batch_id);

        if (! $batch instanceof Batch) {
            return null;
        }

        $failed = $this->batchFailedJobCount($batch);
        $total = $batch->totalJobs;
        $successful = max(0, $total - $batch->pendingJobs);

        return new MailboxHistoryImportSummary(
            totalJobs: $total,
            successfulJobs: $successful,
            failedJobs: $failed,
            finished: $this->batchIsComplete($batch),
        );
    }

    public function batchIsComplete(Batch $batch): bool
    {
        if ($batch->finished()) {
            return true;
        }

        if ($batch->totalJobs === 0) {
            return false;
        }

        if ($batch->pendingJobs === 0) {
            return true;
        }

        return $batch->pendingJobs === $this->batchFailedJobCount($batch);
    }

    public function processedJobCount(ConnectedAccount $account): int
    {
        $batch = $this->historyImportBatch($account);

        if ($batch instanceof Batch && $batch->totalJobs > 0) {
            return $this->batchProcessedJobCount($batch);
        }

        return $account->initial_sync_imported;
    }

    public function totalJobCount(ConnectedAccount $account): int
    {
        $batch = $this->historyImportBatch($account);

        if ($batch instanceof Batch && $batch->totalJobs > 0) {
            return $batch->totalJobs;
        }

        if (filled($account->history_import_batch_id)) {
            return 0;
        }

        $estimated = $account->initial_sync_estimated;

        return is_int($estimated) && $estimated > 0 ? $estimated : max(1, $account->initial_sync_imported);
    }

    public function progressPercent(ConnectedAccount $account): int
    {
        if ($this->shouldCombineCalendarProgress($account)) {
            return $this->combinedProgressPercent($account);
        }

        return $this->emailProgressPercent($account);
    }

    /**
     * Jobs that have left the queue (success or permanent failure), not still pending.
     */
    public function batchProcessedJobCount(Batch $batch): int
    {
        return max(0, $batch->totalJobs - $batch->pendingJobs);
    }

    /**
     * Store jobs that finished without a permanent batch failure.
     */
    public function batchSuccessfulJobCount(Batch $batch): int
    {
        if ($batch->totalJobs <= 0) {
            return 0;
        }

        return max(0, min($batch->totalJobs, $batch->totalJobs - $batch->pendingJobs));
    }

    /**
     * Distinct unresolved batch jobs, not Laravel's cumulative failed-attempt counter.
     */
    private function batchFailedJobCount(Batch $batch): int
    {
        return count($batch->failedJobIds);
    }

    /**
     * Successful store jobs over the current batch size. Failures surface in import
     * notifications; the bar reflects messages stored, not queue exhaustion alone.
     *
     * @return int<0, 100>
     */
    public function batchProgressPercent(Batch $batch, ConnectedAccount $account): int
    {
        if ($batch->totalJobs <= 0) {
            return 0;
        }

        $percent = (int) round(($this->batchSuccessfulJobCount($batch) / $batch->totalJobs) * 100);
        $percent = max(0, min(100, $percent));

        if ($this->isEmailListingInProgress($account)) {
            return min(99, $percent);
        }

        return $percent;
    }

    /**
     * @return int<0, 100>
     */
    private function emailProgressPercent(ConnectedAccount $account): int
    {
        $batch = $this->historyImportBatch($account);

        if ($batch instanceof Batch) {
            if ($batch->totalJobs === 0) {
                return $account->sync_cursor !== null ? 100 : 0;
            }

            return $this->batchProgressPercent($batch, $account);
        }

        if ($account->sync_cursor !== null) {
            return 100;
        }

        if (filled($account->history_import_batch_id)) {
            return 0;
        }

        return $account->initialSyncProgressPercent();
    }

    private function shouldCombineCalendarProgress(ConnectedAccount $account): bool
    {
        if (! $account->hasCalendar()) {
            return false;
        }

        $batchId = $account->history_import_batch_id;

        return is_string($batchId) && $batchId !== '' && $this->isCalendarImportPending($batchId);
    }

    /**
     * @return int<0, 100>
     */
    private function combinedProgressPercent(ConnectedAccount $account): int
    {
        [$emailDone, $emailTotal] = $this->emailProgressCounts($account);
        $batchId = (string) $account->history_import_batch_id;
        [$calendarDone, $calendarTotal] = $this->calendarProgressCounts($account, $batchId);

        $emailFraction = $emailTotal > 0
            ? min(1.0, $emailDone / max($emailTotal, $emailDone))
            : ($account->sync_cursor !== null ? 1.0 : 0.0);

        $calendarFraction = $calendarTotal > 0
            ? min(1.0, $calendarDone / max($calendarTotal, $calendarDone))
            : 0.0;

        $percent = (int) round((($emailFraction + $calendarFraction) / 2) * 100);
        $percent = max(0, min(100, $percent));

        if ($this->isEmailListingInProgress($account) || $this->isCalendarImportPending($batchId)) {
            $percent = min(99, $percent);
        }

        return $this->rememberProgressHighWater($batchId, $percent);
    }

    /**
     * @return int<0, 100>
     */
    private function rememberProgressHighWater(string $batchId, int $percent): int
    {
        $key = self::PROGRESS_HIGH_WATER_PREFIX.$batchId;
        $highWater = max($percent, (int) Cache::get($key, 0));

        Cache::put($key, $highWater, now()->addMonth());

        return max(0, min(100, $highWater));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function emailProgressCounts(ConnectedAccount $account): array
    {
        $batch = $this->historyImportBatch($account);

        if ($batch instanceof Batch && $batch->totalJobs > 0) {
            return [
                $this->batchSuccessfulJobCount($batch),
                $batch->totalJobs,
            ];
        }

        if (filled($account->history_import_batch_id)) {
            return [0, 0];
        }

        $estimated = $account->initial_sync_estimated;
        $imported = $account->initial_sync_imported;
        $total = is_int($estimated) && $estimated > 0 ? $estimated : max(1, $imported);

        return [$imported, $total];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function calendarProgressCounts(ConnectedAccount $account, string $batchId): array
    {
        $discovered = max(
            $this->calendarDiscoveredCount($batchId),
            MailboxSyncTracker::calendarRunTotal($account),
        );

        $done = MailboxSyncTracker::isCalendarSyncing($account)
            ? MailboxSyncTracker::calendarProcessedCount($account)
            : max(0, $account->initial_calendar_sync_imported);

        return [$done, $discovered];
    }

    private function historyImportBatch(ConnectedAccount $account): ?Batch
    {
        if (blank($account->history_import_batch_id)) {
            return null;
        }

        $batch = Bus::findBatch($account->history_import_batch_id);

        return $batch instanceof Batch ? $batch : null;
    }

    public function startBatch(ConnectedAccount $account): Batch
    {
        $accountId = (string) $account->getKey();
        $complete = static function (Batch $batch) use ($accountId): void {
            resolve(CompleteMailboxHistoryImportAction::class)->execute($accountId, $batch->id);
        };

        return Bus::batch([])
            ->name("Mailbox history import: {$account->email_address}")
            ->onQueue('emails-sync')
            ->allowFailures()
            ->progress($complete)
            ->finally($complete)
            ->dispatch();
    }

    /**
     * @param  list<string>  $messageIds
     */
    public function addStoreJobs(ConnectedAccount $account, array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $batchId = $account->history_import_batch_id;

        if (! is_string($batchId) || $batchId === '') {
            return;
        }

        $batch = Bus::findBatch($batchId);

        if (! $batch instanceof Batch) {
            return;
        }

        $jobs = collect(array_values(array_unique($messageIds)))
            ->chunk(Config::integer('email-integration.sync.batch_size', 50))
            ->flatMap(fn (Collection $chunk): array => $chunk
                ->map(fn (string $id): StoreEmailJob => new StoreEmailJob($account, $id))
                ->all())
            ->all();

        $batch->add($jobs);
    }
}
