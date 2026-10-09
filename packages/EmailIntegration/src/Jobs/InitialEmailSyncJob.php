<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\Skip;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Actions\CompleteMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\MailboxImportPass;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Jobs\Concerns\ReleasesOnProviderRateLimit;
use Relaticle\EmailIntegration\Jobs\Middleware\HandlesProviderFailures;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Throwable;

#[DeleteWhenMissingModels]
#[Backoff(60, 300, 900)]
#[MaxExceptions(3)]
#[Queue('emails-import')]
#[Timeout(300)]
#[UniqueFor(3600)]
final class InitialEmailSyncJob implements ShouldBeUnique, ShouldQueue
{
    use DetectsAuthErrors, Queueable, ReleasesOnProviderRateLimit;

    // Never a constructor argument: a page queued before passes existed unserializes without it and needs this default.
    public MailboxImportPass $pass = MailboxImportPass::Full;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly ?string $pageToken = null,
        public readonly ?string $historyCursor = null,
        public readonly ?string $historyImportBatchId = null,
    ) {}

    public function forPass(MailboxImportPass $pass): static
    {
        $this->pass = $pass;

        // Listing recent mail must not wait behind another mailbox's history on emails-import.
        if ($pass === MailboxImportPass::Recent) {
            $this->onQueue('emails-sync');
        }

        return $this;
    }

    public function retryUntil(): CarbonImmutable
    {
        return now()->addDay();
    }

    /**
     * @return list<Skip|HandlesProviderFailures>
     */
    public function middleware(): array
    {
        return [Skip::when($this->connectedAccount->trashed()), new HandlesProviderFailures];
    }

    /**
     * @throws Throwable
     */
    public function handle(
        MailServiceFactoryInterface $mailFactory,
        MailboxHistoryImportService $mailboxHistoryImport,
    ): void {
        $account = $this->connectedAccount;
        $accountId = (string) $account->getKey();

        if ($this->isSuperseded($account)) {
            return;
        }

        if ($this->releaseIfProviderCoolingDown($accountId)) {
            return;
        }

        $mailboxHistoryImport->markEmailListingStarted($account);
        $service = $mailFactory->make($account);

        try {
            $page = $service->initialBackfill($this->daysBack(), $this->pageToken);
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
        $newIds = $this->unstoredIds($account, $allIds);
        $historyBatchId = $this->resolveHistoryImportBatchId($account);
        $pass = $this->pass;

        if ($historyBatchId !== null && $page->cursor !== null) {
            self::startSyncingNewMail($account, $page->cursor);
        }

        $nextPageToken = $page->nextPageToken;
        $pageCursor = $page->cursor;

        if ($newIds === [] || $historyBatchId !== null) {
            $mailboxHistoryImport->addStoreJobs($account, $newIds);
            self::continueOrFinish($account, $pass, $historyCursor, $nextPageToken, $pageCursor, $historyBatchId);

            return;
        }

        InitialSyncPageStoreBatch::dispatchEmails(
            account: $account,
            pageMessageIds: $allIds,
            messageIdsToStore: $newIds,
            historyCursor: $historyCursor,
            nextPageToken: $nextPageToken,
            pageCursor: $pageCursor,
            onPageStored: static function (ConnectedAccount $account) use ($pass, $historyCursor, $nextPageToken, $pageCursor): void {
                self::continueOrFinish($account, $pass, $historyCursor, $nextPageToken, $pageCursor);
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

    /**
     * @param  list<string>  $messageIds
     * @return list<string>
     */
    private function unstoredIds(ConnectedAccount $account, array $messageIds): array
    {
        $storedIds = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->whereIn('provider_message_id', $messageIds)
            ->pluck('provider_message_id')
            ->all();

        return array_values(array_diff($messageIds, $storedIds));
    }

    private static function startSyncingNewMail(ConnectedAccount $account, string $cursor): void
    {
        $account->update([
            'sync_cursor' => $cursor,
            'last_synced_at' => now(),
            'status' => EmailAccountStatus::ACTIVE,
            'last_error' => null,
        ]);
    }

    private function isSuperseded(ConnectedAccount $account): bool
    {
        return $this->historyImportBatchId !== null
            && $account->history_import_batch_id !== $this->historyImportBatchId;
    }

    public function failed(Throwable $exception): void
    {
        $mailboxHistoryImport = resolve(MailboxHistoryImportService::class);
        $mailboxHistoryImport->markEmailListingFinished($this->connectedAccount);

        if ($this->historyImportBatchId !== null) {
            $mailboxHistoryImport->markHistoryListingFinished($this->historyImportBatchId);
        }

        $this->connectedAccount->update([
            'status' => $this->isAuthError($exception) ? EmailAccountStatus::REAUTH_REQUIRED : EmailAccountStatus::ERROR,
            'last_error' => $exception->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        $page = $this->pageToken ?? 'start';
        $batchId = $this->historyImportBatchId ?? 'none';

        return 'initial-sync-'.$this->connectedAccount->getKey().'-'.hash('xxh3', $page.':'.$batchId.':'.$this->pass->value);
    }

    private function daysBack(): ?int
    {
        if ($this->pass === MailboxImportPass::Recent) {
            return MailboxImportPass::RECENT_DAYS;
        }

        $days = Config::get('email-integration.sync.initial_days');

        if (! is_numeric($days) || (int) $days <= 0) {
            return null;
        }

        return (int) $days;
    }

    private static function continueOrFinish(
        ConnectedAccount $account,
        MailboxImportPass $pass,
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
            dispatch(new self($account, $nextPageToken, $historyCursor, $historyImportBatchId)->forPass($pass));

            return;
        }

        $cursor = $historyCursor ?? $pageCursor;

        if ($account->sync_cursor === null && $cursor !== null) {
            self::startSyncingNewMail($account, $cursor);
        }

        if ($pass === MailboxImportPass::Recent) {
            dispatch(new self($account, historyImportBatchId: $historyImportBatchId));

            return;
        }

        $mailboxHistoryImport = resolve(MailboxHistoryImportService::class);
        $mailboxHistoryImport->markEmailListingFinished($account);

        if ($historyImportBatchId !== null) {
            $mailboxHistoryImport->markHistoryListingFinished($historyImportBatchId);
            resolve(CompleteMailboxHistoryImportAction::class)->execute((string) $account->getKey(), $historyImportBatchId);

            return;
        }

        $account->user?->notify(new MailboxHistoryImportCompletedNotification($account->fresh() ?? $account));
    }
}
