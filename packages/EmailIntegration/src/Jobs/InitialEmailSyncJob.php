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

        $account->update(['sync_cursor' => $data['cursor']]);

        $allIds = $data['message_ids']->all();

        // Bulk dedup: exclude IDs that are already stored for this account
        $storedIds = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->whereIn('provider_message_id', $allIds)
            ->pluck('provider_message_id')
            ->all();

        $newIds = array_values(array_diff($allIds, $storedIds));

        if ($newIds === []) {
            return;
        }

        $jobs = collect($newIds)
            ->chunk(config('services.email_sync.batch_size', 50))
            ->flatMap(fn (Collection $chunk): array => $chunk->map(fn (string $id): StoreEmailJob => new StoreEmailJob($account, $id))->all())
            ->all();

        Bus::batch($jobs)
            ->name("Initial sync: {$account->email_address}")
            ->onQueue('emails-sync')
            ->allowFailures()
            ->dispatch();
    }

    public function uniqueId(): string
    {
        return "initial-sync-{$this->connectedAccount->getKey()}";
    }
}
