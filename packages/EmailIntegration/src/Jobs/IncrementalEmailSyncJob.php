<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Exceptions\MailHistoryExpired;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;
use Throwable;

#[DeleteWhenMissingModels]
final class IncrementalEmailSyncJob implements ShouldBeUnique, ShouldQueue
{
    use DetectsAuthErrors, Queueable;

    public int $tries = 3;

    /** @var array<int, int> Spaced retry delays so transient 429/5xx don't hammer the provider */
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

        MailboxSyncTracker::markEmailStarted($account);

        $service = $mailFactory->make($account);

        try {
            $delta = $service->fetchDelta($account->sync_cursor);
        } catch (MailHistoryExpired) {
            MailboxSyncTracker::markEmailFinished($account);
            $account->update(['sync_cursor' => null]);
            dispatch(new InitialEmailSyncJob($account));

            return;
        }

        $allIds = $delta->messageIds->all();

        // Bulk dedup: exclude IDs already stored for this account
        $storedIds = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->whereIn('provider_message_id', $allIds)
            ->pluck('provider_message_id')
            ->all();

        $newIds = array_values(array_diff($allIds, $storedIds));

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

        // Advancing the cursor before the fetched messages are stored loses any message
        // whose StoreEmailJob exhausts its retries: the next sync starts past it and it
        // is never retried. So advance the cursor only once the batch has fully stored.
        // With no new messages the delta is read-only state, so advance inline.
        if ($newIds === []) {
            $this->advanceCursor($account, $delta->newCursor);

            return;
        }

        MailboxSyncTracker::setEmailRunTotal($account, count($newIds));

        $accountId = (string) $account->getKey();
        $newCursor = $delta->newCursor;

        Bus::batch(array_map(
            fn (string $messageId): StoreEmailJob => new StoreEmailJob($account, $messageId),
            $newIds,
        ))
            ->name("Incremental sync: {$account->email_address}")
            ->onQueue('emails-sync')
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($accountId, $newCursor): void {
                $account = ConnectedAccount::query()->whereKey($accountId)->first();

                if (! $account instanceof ConnectedAccount) {
                    return;
                }

                if ($batch->failedJobs > 0) {
                    $account->update([
                        'last_synced_at' => now(),
                        'status' => EmailAccountStatus::ERROR,
                        'last_error' => "{$batch->failedJobs} email(s) could not be stored during sync.",
                    ]);

                    MailboxSyncTracker::markEmailFinished($account);

                    return;
                }

                $account->update([
                    'sync_cursor' => $newCursor,
                    'last_synced_at' => now(),
                    'status' => EmailAccountStatus::ACTIVE,
                    'last_error' => null,
                ]);

                MailboxSyncTracker::markEmailFinished($account);
            })
            ->dispatch();
    }

    private function advanceCursor(ConnectedAccount $account, string $newCursor): void
    {
        $account->update([
            'sync_cursor' => $newCursor,
            'last_synced_at' => now(),
            'status' => EmailAccountStatus::ACTIVE,
            'last_error' => null,
        ]);

        MailboxSyncTracker::markEmailFinished($account);
    }

    public function failed(Throwable $exception): void
    {
        MailboxSyncTracker::markEmailFinished($this->connectedAccount);

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
