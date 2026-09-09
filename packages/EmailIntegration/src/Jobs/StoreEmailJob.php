<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Jobs\Concerns\ReleasesOnProviderRateLimit;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Throwable;

#[DeleteWhenMissingModels]
final class StoreEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, ReleasesOnProviderRateLimit, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly string $messageId,
    ) {
        $this->onQueue('emails-sync');
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
     * @throws Throwable
     */
    public function handle(MailServiceFactoryInterface $mailFactory, StoreEmailAction $action): void
    {
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

            throw $exception;
        }

        // Provider drafts are unsent. Gmail drafts carry DRAFT and not SENT, so
        // fetchMessage() classifies them as inbound. Skip them here rather than
        // in a provider service so it covers Gmail and Microsoft, and both the
        // initial backfill and incremental syncs. Otherwise they store as SYNCED
        // with the account's sharing default, and teammates can read them
        // through linked CRM records.
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

    private function doesItAlreadyExists(): bool
    {
        return Email::query()
            ->where('connected_account_id', $this->connectedAccount->getKey())
            ->where('provider_message_id', $this->messageId)
            ->exists();
    }
}
