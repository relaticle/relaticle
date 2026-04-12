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
use Relaticle\EmailIntegration\Models\Email;
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

        if ($account->provider !== EmailProvider::GMAIL) {
            return;
        }

        $service = new GmailService($account);
        $data = $service->fetchDelta($account->sync_cursor);

        Log::info("Fetched delta for account {$account->id}: ".json_encode($data));

        $allIds = $data['message_ids']->all();

        // Bulk dedup: exclude IDs already stored for this account
        $storedIds = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->whereIn('provider_message_id', $allIds)
            ->pluck('provider_message_id')
            ->all();

        $newIds = array_values(array_diff($allIds, $storedIds));

        foreach ($newIds as $messageId) {
            dispatch(new StoreEmailJob($account, $messageId));
        }

        $account->update([
            'sync_cursor' => $data['new_history_id'],
            'last_synced_at' => now(),
            'status' => EmailAccountStatus::ACTIVE,
            'last_error' => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $isAuthError = str_contains($exception->getMessage(), 'invalid_grant')
            || str_contains($exception->getMessage(), '401');

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
