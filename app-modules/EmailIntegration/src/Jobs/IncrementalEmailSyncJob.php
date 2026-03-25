<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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

        if ($account->status !== EmailAccountStatus::ACTIVE || ! $account->sync_cursor) {
            return;
        }

        if ($account->provider === EmailProvider::GMAIL) {
            $service = new GmailService($account);
            $data = $service->fetchDelta($account->sync_cursor);

            Log::info("Fetched delta for account {$account->id}: ".json_encode($data));

            foreach ($data['message_ids'] as $messageId) {
                StoreEmailJob::dispatch($account, $messageId, EmailProvider::GMAIL);
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
        $isAuthError = str_contains($exception->getMessage(), 'invalid_grant')
            || str_contains($exception->getMessage(), '401');

        //        Log::info($exception->getMessage());

        $this->connectedAccount->update([
            'status' => $isAuthError ? EmailAccountStatus::REAUTH_REQUIRED : EmailAccountStatus::ERROR,
            'last_error' => $exception->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return "incremental-sync-{$this->connectedAccount->getKey()}";
    }
}
