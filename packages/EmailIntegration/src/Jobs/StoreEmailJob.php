<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\GmailService;
use Throwable;

final class StoreEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 5;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly string $messageId,
    ) {}

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
    public function handle(StoreEmailAction $action): void
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

        $fetched = match ($this->connectedAccount->provider) {
            EmailProvider::GMAIL => GmailService::forAccount($this->connectedAccount)->fetchMessage($this->messageId),
            EmailProvider::AZURE => throw new Exception('Azure email provider is not yet implemented.'),
        };

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
