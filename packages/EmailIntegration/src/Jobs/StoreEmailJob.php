<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Actions\RecordMailboxHistoryImportStoreFailureAction;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Jobs\Concerns\ReleasesOnProviderRateLimit;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Throwable;

#[DeleteWhenMissingModels]
#[MaxExceptions(3)]
final class StoreEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Queueable, ReleasesOnProviderRateLimit;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @var array<int, int> Spaced retry delays so transient 429/5xx don't hammer the provider. */
    public array $backoff;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly string $messageId,
    ) {
        $this->onQueue('emails-sync');
        $this->backoff = $this->resolveStoreBackoff();
    }

    /**
     * @return list<int>
     */
    private function resolveStoreBackoff(): array
    {
        $configured = Config::array('email-integration.sync.store_backoff');

        if ($configured === []) {
            return [60, 300, 900];
        }

        return array_values(array_map(
            static fn (mixed $seconds): int => (int) $seconds,
            $configured,
        ));
    }

    /**
     * Unique key prevents duplicate jobs for the same account + message from
     * being queued simultaneously (e.g. overlapping incremental syncs).
     */
    public function uniqueId(): string
    {
        return "store-email-{$this->connectedAccount->getKey()}-{$this->messageId}";
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->uniqueId())
                ->releaseAfter(15)
                ->expireAfter(300),
        ];
    }

    /**
     * @throws Throwable
     */
    public function handle(
        MailServiceFactoryInterface $mailFactory,
        StoreEmailAction $action,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        /**
         * Last-line-of-defense dedup: the sync job filters by ID before dispatching,
         * but two syncs can race and both dispatch this job before either stores.
         * This check is cheap (one indexed query) and happens before the API call.
         **/
        if ($this->doesItAlreadyExists()) {
            return;
        }

        $accountId = (string) $this->connectedAccount->getKey();

        if ($this->releaseIfProviderCoolingDown($accountId)) {
            return;
        }

        try {
            $fetched = $mailFactory->make($this->connectedAccount)->fetchMessage($this->messageId);
        } catch (Throwable $exception) {
            if ($this->releaseIfProviderRateLimited($accountId, $exception)) {
                return;
            }

            // Permanently deleted between list and fetch. Retrying a 404 fails
            // the batch and parks the mailbox as ERROR, stopping later imports.
            if ($this->isMissingProviderMessage($exception)) {
                return;
            }

            throw $exception;
        }

        /**
         * Provider drafts are unsent. Gmail drafts carry DRAFT and not SENT, so
         * fetchMessage() classifies them as inbound. Skip them here rather than
         * in a provider service so it covers Gmail and Microsoft, and both the
         * initial backfill and incremental syncs. Otherwise they store as SYNCED
         * with the account's sharing default, and teammates can read them
         * through linked CRM records.
         **/
        if ($fetched->folder === EmailFolder::Drafts) {
            return;
        }

        // Honour the account's inbox/sent toggles. Gated here rather than in a
        // provider service so it covers Gmail and Microsoft, and both the initial
        // backfill and incremental syncs, in one place. Re-read from the DB on
        // unserialize (SerializesModels), so a toggle change before this job runs
        // takes effect.
        if (! $this->connectedAccount->syncsDirection($fetched->direction)) {
            return;
        }

        $action->execute($this->connectedAccount, $fetched);
    }

    public function failed(Throwable $exception): void
    {
        $batch = $this->batch();
        $batchId = $batch?->id;
        $historyBatchId = $this->connectedAccount->history_import_batch_id;

        if (is_string($batchId) && is_string($historyBatchId) && $batchId === $historyBatchId) {
            resolve(RecordMailboxHistoryImportStoreFailureAction::class)->execute(
                $this->connectedAccount,
                $historyBatchId,
            );
        }
    }

    private function doesItAlreadyExists(): bool
    {
        return Email::query()
            ->where('connected_account_id', $this->connectedAccount->getKey())
            ->where('provider_message_id', $this->messageId)
            ->exists();
    }

    private function isMissingProviderMessage(Throwable $exception): bool
    {
        $current = $exception;

        while ($current instanceof Throwable) {
            if ($current instanceof RequestException) {
                return $current->response->status() === 404;
            }

            $code = $current->getCode();

            if (is_int($code) && $code === 404) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }
}
