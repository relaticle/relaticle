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
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Throwable;

#[DeleteWhenMissingModels]
final class IncrementalEmailSyncJob implements ShouldBeUnique, ShouldQueue
{
    use DetectsAuthErrors, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> Spaced retry delays so transient 429/5xx don't hammer the provider. */
    public array $backoff = [60, 300, 900];

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

        // Read state is per-viewer; the provider delta reflects the OWNER's mailbox,
        // so toggle only the owner's read rows (teammates' read state is untouched).
        $ownerId = $account->user_id;

        // Mark emails as read when the UNREAD label was removed in Gmail.
        $readIds = $delta->readMessageIds->all();

        if ($readIds !== []) {
            $readEmailIds = Email::query()
                ->where('connected_account_id', $account->getKey())
                ->whereIn('provider_message_id', $readIds)
                ->pluck('id');

            foreach ($readEmailIds as $emailId) {
                EmailRead::query()->firstOrCreate(
                    ['email_id' => $emailId, 'user_id' => $ownerId],
                    ['read_at' => now()],
                );
            }
        }

        // Mark emails unread again when the provider flipped them back to unread.
        $unreadIds = $delta->unreadMessageIds?->all() ?? [];

        if ($unreadIds !== []) {
            $unreadEmailIds = Email::query()
                ->where('connected_account_id', $account->getKey())
                ->whereIn('provider_message_id', $unreadIds)
                ->pluck('id');

            EmailRead::query()
                ->whereIn('email_id', $unreadEmailIds)
                ->where('user_id', $ownerId)
                ->delete();
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
        $this->connectedAccount->update([
            'status' => $this->isAuthError($exception) ? EmailAccountStatus::REAUTH_REQUIRED : EmailAccountStatus::ERROR,
            'last_error' => $exception->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return "incremental-sync-{$this->connectedAccount->getKey()}";
    }
}
