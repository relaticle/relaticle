<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\GmailService;
use Throwable;

final class IncrementalEmailSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
    ) {}

    public function handle(): void
    {
        $account = $this->connectedAccount;

        if (! $account->isActive() || ! $account->sync_cursor) {
            return;
        }

        if ($account->provider === EmailProvider::GMAIL) {
            $service = new GmailService($account);
            $data = $service->fetchDelta($account->sync_cursor);

            foreach ($data['message_ids'] as $messageId) {
                StoreEmailJob::dispatch($account, $messageId, 'gmail');
            }

            $account->update([
                'sync_cursor' => $data['new_history_id'],
                'last_synced_at' => now(),
                'status' => EmailAccountStatus::ACTIVE,
                'last_error' => null,
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->connectedAccount->update([
            'status' => EmailAccountStatus::ERROR,
            'last_error' => $exception->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return "incremental-sync-{$this->connectedAccount->getKey()}";
    }
}
