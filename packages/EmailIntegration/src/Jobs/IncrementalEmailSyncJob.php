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
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Throwable;

#[DeleteWhenMissingModels]
final class IncrementalEmailSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
    ) {
        $this->onQueue('emails-sync');
    }

    public function handle(MailServiceFactoryInterface $mailFactory): void
    {
        $account = $this->connectedAccount;

        if ($account->status !== EmailAccountStatus::ACTIVE || ! $account->sync_cursor) {
            return;
        }

        $service = $mailFactory->make($account);
        $delta = $service->fetchDelta($account->sync_cursor);

        $allIds = $delta->messageIds->all();

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

        // Mark emails as read when UNREAD label was removed in Gmail
        $readIds = $delta->readMessageIds->all();

        if ($readIds !== []) {
            Email::query()
                ->where('connected_account_id', $account->getKey())
                ->whereIn('provider_message_id', $readIds)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        $account->update([
            'sync_cursor' => $delta->newCursor,
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
