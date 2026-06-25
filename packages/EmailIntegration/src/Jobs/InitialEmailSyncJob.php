<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;

#[DeleteWhenMissingModels]
final class InitialEmailSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
    ) {
        $this->onQueue('emails-sync');
    }

    /**
     * @throws \Throwable
     */
    public function handle(MailServiceFactoryInterface $mailFactory): void
    {
        $account = $this->connectedAccount;

        $daysBack = Config::integer('email-integration.sync.initial_days', 90);

        $service = $mailFactory->make($account);

        $data = $service->initialBackfill($daysBack);

        $allIds = $data['message_ids']->all();

        // Bulk dedup: exclude IDs that are already stored for this account
        $storedIds = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->whereIn('provider_message_id', $allIds)
            ->pluck('provider_message_id')
            ->all();

        $newIds = array_values(array_diff($allIds, $storedIds));

        // Persist the cursor only after the backfill batch has fully stored. Setting it
        // up front means a StoreEmailJob that exhausts its retries is skipped forever:
        // incremental sync starts from a cursor already past the unstored message.
        if ($newIds === []) {
            $account->update(['sync_cursor' => $data['cursor']]);

            return;
        }

        $accountId = $account->getKey();
        $cursor = $data['cursor'];

        $jobs = collect($newIds)
            ->chunk(Config::integer('email-integration.sync.batch_size', 50))
            ->flatMap(fn (Collection $chunk): array => $chunk->map(fn (string $id): StoreEmailJob => new StoreEmailJob($account, $id))->all())
            ->all();

        Bus::batch($jobs)
            ->name("Initial sync: {$account->email_address}")
            ->onQueue('emails-sync')
            ->allowFailures()
            // then() runs only when every job succeeded; with a failure the batch still
            // completes (allowFailures) but the cursor stays null, so the backfill is
            // re-attempted next sync (dedup makes the re-fetch cheap) instead of dropping
            // the unstored message.
            ->then(function () use ($accountId, $cursor): void {
                ConnectedAccount::query()->find($accountId)?->update(['sync_cursor' => $cursor]);
            })
            ->dispatch();
    }

    public function uniqueId(): string
    {
        return "initial-sync-{$this->connectedAccount->getKey()}";
    }
}
