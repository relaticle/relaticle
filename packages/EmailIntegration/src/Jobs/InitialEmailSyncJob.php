<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Google\Service\Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\GmailService;

final class InitialEmailSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $timeout = 300;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
    ) {
        $this->onQueue('emails-sync');
    }

    /**
     * @throws \Throwable
     * @throws Exception
     */
    public function handle(): void
    {
        $account = $this->connectedAccount;

        if ($account->provider !== EmailProvider::GMAIL) {
            return;
        }

        $daysBack = Config::integer('email-integration.sync.initial_days', 90);

        $service = GmailService::forAccount($account);

        $data = $service->fetchInitialMessages($daysBack);

        $account->update(['sync_cursor' => $data['history_id']]);

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
            ->allowFailures()
            ->dispatch();
    }

    public function uniqueId(): string
    {
        return "initial-sync-{$this->connectedAccount->getKey()}";
    }
}
