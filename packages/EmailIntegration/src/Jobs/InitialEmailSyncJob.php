<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Actions\CompleteMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Jobs\Concerns\ReleasesOnProviderRateLimit;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Throwable;

#[DeleteWhenMissingModels]
#[Backoff(60, 300, 900)]
#[Queue('emails-sync')]
#[Timeout(300)]
#[Tries(3)]
#[UniqueFor(3600)]
final class InitialEmailSyncJob implements ShouldBeUnique, ShouldQueue
{
    use DetectsAuthErrors, Queueable, ReleasesOnProviderRateLimit;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly ?string $pageToken = null,
        public readonly ?string $historyCursor = null,
        public readonly ?string $historyImportBatchId = null,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(
        MailServiceFactoryInterface $mailFactory,
        MailboxHistoryImportService $mailboxHistoryImport,
    ): void {
        $account = $this->connectedAccount;
        $accountId = (string) $account->getKey();

        if ($this->releaseIfProviderCoolingDown($accountId)) {
            return;
        }

        $mailboxHistoryImport->markEmailListingStarted($account);
        $service = $mailFactory->make($account);

        try {
            $page = $service->initialBackfill($this->initialDaysCap(), $this->pageToken);
        } catch (Throwable $exception) {
            $mailboxHistoryImport->markEmailListingFinished($account);

            if ($this->releaseIfProviderRateLimited($accountId, $exception)) {
                return;
            }

            throw $exception;
        }

        $historyCursor = $this->historyCursor ?? $page->cursor;

        if ($this->pageToken === null && $page->estimatedTotal !== null) {
            $account->update(['initial_sync_estimated' => $page->estimatedTotal]);
        }

        $allIds = array_values($page->messageIds->all());

        $storedIds = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->whereIn('provider_message_id', $allIds)
            ->pluck('provider_message_id')
            ->all();

        $newIds = array_values(array_diff($allIds, $storedIds));

        $historyBatchId = $this->resolveHistoryImportBatchId($account);

        if ($newIds === []) {
            self::continueOrFinish($account, $historyCursor, $page->nextPageToken, $page->cursor, $historyBatchId);

            return;
        }

        $nextPageToken = $page->nextPageToken;
        $pageCursor = $page->cursor;

        if ($historyBatchId !== null) {
            $mailboxHistoryImport->addStoreJobs($account, $newIds);
            self::continueOrFinish($account, $historyCursor, $nextPageToken, $pageCursor, $historyBatchId);

            return;
        }

        InitialSyncPageStoreBatch::dispatchEmails(
            account: $account,
            pageMessageIds: $allIds,
            messageIdsToStore: $newIds,
            historyCursor: $historyCursor,
            nextPageToken: $nextPageToken,
            pageCursor: $pageCursor,
            onPageStored: static function (ConnectedAccount $account) use ($historyCursor, $nextPageToken, $pageCursor): void {
                self::continueOrFinish($account, $historyCursor, $nextPageToken, $pageCursor);
            },
        );
    }

    private function resolveHistoryImportBatchId(ConnectedAccount $account): ?string
    {
        $batchId = $this->historyImportBatchId ?? $account->history_import_batch_id;

        if (! is_string($batchId) || $batchId === '') {
            return null;
        }

        return $batchId;
    }

    public function failed(Throwable $exception): void
    {
        resolve(MailboxHistoryImportService::class)->markEmailListingFinished($this->connectedAccount);

        $this->connectedAccount->update([
            'status' => $this->isAuthError($exception) ? EmailAccountStatus::REAUTH_REQUIRED : EmailAccountStatus::ERROR,
            'last_error' => $exception->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        $page = $this->pageToken ?? 'start';
        $batchId = $this->historyImportBatchId ?? 'none';

        return 'initial-sync-'.$this->connectedAccount->getKey().'-'.hash('xxh3', $page.':'.$batchId);
    }

    private function initialDaysCap(): ?int
    {
        $days = Config::get('email-integration.sync.initial_days');

        if (! is_numeric($days) || (int) $days <= 0) {
            return null;
        }

        return (int) $days;
    }

    private static function continueOrFinish(
        ConnectedAccount $account,
        ?string $historyCursor,
        ?string $nextPageToken,
        ?string $pageCursor,
        ?string $historyImportBatchId = null,
    ): void {
        $imported = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->count();

        $account->update(['initial_sync_imported' => $imported]);

        if ($nextPageToken !== null && $nextPageToken !== '') {
            dispatch(new self($account, $nextPageToken, $historyCursor, $historyImportBatchId));

            return;
        }

        $cursor = $historyCursor ?? $pageCursor;

        resolve(MailboxHistoryImportService::class)->markEmailListingFinished($account);

        $account->update([
            'sync_cursor' => $cursor,
            'last_synced_at' => now(),
            'initial_sync_imported' => $imported,
            'status' => EmailAccountStatus::ACTIVE,
            'last_error' => null,
        ]);

        if ($historyImportBatchId !== null) {
            resolve(CompleteMailboxHistoryImportAction::class)->execute((string) $account->getKey(), $historyImportBatchId);

            return;
        }

        $account->user?->notify(new MailboxHistoryImportCompletedNotification($account->fresh() ?? $account));
    }
}
