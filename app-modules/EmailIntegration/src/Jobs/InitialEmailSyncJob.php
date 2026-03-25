<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\GmailService;

final class InitialEmailSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $timeout = 300;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
    ) {
        //
    }

    public function handle(): void
    {
        $account = $this->connectedAccount;

        $daysBack = (int) config('email-integration.sync.initial_days', 90);

        if ($account->provider === EmailProvider::GMAIL) {
            $service = new GmailService($account);

            $data = $service->fetchInitialMessages($daysBack);

            // Persist historyId cursor — used by incremental sync from now on
            $account->update(['sync_cursor' => $data['history_id']]);

            // Chunk message IDs and dispatch one StoreEmailJob per message
            $jobs = collect($data['message_ids'])
                ->chunk(config('services.email_sync.batch_size', 50))
                ->map(fn ($chunk) => $chunk->map(fn ($id) => new StoreEmailJob($account, $id, EmailProvider::GMAIL))->all())
                ->flatten()
                ->all();

            Bus::batch($jobs)
                ->name("Initial sync: {$account->email_address}")
                ->allowFailures()
                ->dispatch();
        }
    }

    public function uniqueId(): string
    {
        return "initial-sync-{$this->connectedAccount->getKey()}";
    }
}
