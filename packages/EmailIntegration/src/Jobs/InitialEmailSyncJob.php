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
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Throwable;

#[DeleteWhenMissingModels]
final class InitialEmailSyncJob implements ShouldBeUnique, ShouldQueue
{
    use DetectsAuthErrors, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /** @var array<int, int> Spaced retry delays so transient 429/5xx don't hammer the provider. */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly ?string $pageToken = null,
        public readonly ?string $historyCursor = null,
    ) {
        $this->onQueue('emails-sync');
    }

    /**
     * @throws Throwable
     */
    public function handle(MailServiceFactoryInterface $mailFactory): void
    {
        $account = $this->connectedAccount;
        $service = $mailFactory->make($account);
        $page = $service->initialBackfill($this->initialDaysCap(), $this->pageToken);

        $historyCursor = $this->historyCursor ?? $page->cursor;

        if ($this->pageToken === null && $page->estimatedTotal !== null) {
            $account->update(['initial_sync_estimated' => $page->estimatedTotal]);
        }

        $allIds = $page->messageIds->all();

        $storedIds = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->whereIn('provider_message_id', $allIds)
            ->pluck('provider_message_id')
            ->all();

        $newIds = array_values(array_diff($allIds, $storedIds));

        if ($newIds === []) {
            self::continueOrFinish($account, $historyCursor, $page->nextPageToken, $page->cursor);

            return;
        }

        $accountId = (int) $account->getKey();
        $nextPageToken = $page->nextPageToken;
        $pageCursor = $page->cursor;

        $jobs = collect($newIds)
            ->chunk(Config::integer('email-integration.sync.batch_size', 50))
            ->flatMap(fn (Collection $chunk): array => $chunk->map(fn (string $id): StoreEmailJob => new StoreEmailJob($account, $id))->all())
            ->all();

        Bus::batch($jobs)
            ->name("Initial sync: {$account->email_address}")
            ->onQueue('emails-sync')
            ->allowFailures()
            ->then(function () use ($accountId, $historyCursor, $nextPageToken, $pageCursor): void {
                $account = ConnectedAccount::query()->whereKey($accountId)->first();

                if (! $account instanceof ConnectedAccount) {
                    return;
                }

                self::continueOrFinish($account, $historyCursor, $nextPageToken, $pageCursor);
            })
            ->dispatch();
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
        return 'initial-sync-'.$this->connectedAccount->getKey().'-'.hash('xxh3', $this->pageToken ?? 'start');
    }

    private function initialDaysCap(): ?int
    {
        $days = Config::get('email-integration.sync.initial_days');

        if (! is_numeric($days) || (int) $days <= 0) {
            return null;
        }

        return (int) $days;
    }

    private static function continueOrFinish(
        ConnectedAccount $account,
        ?string $historyCursor,
        ?string $nextPageToken,
        ?string $pageCursor,
    ): void {
        $imported = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->count();

        $account->update(['initial_sync_imported' => $imported]);

        if ($nextPageToken !== null && $nextPageToken !== '') {
            dispatch(new self($account, $nextPageToken, $historyCursor));

            return;
        }

        $cursor = $historyCursor ?? $pageCursor;

        $account->update([
            'sync_cursor' => $cursor,
            'last_synced_at' => now(),
            'initial_sync_imported' => $imported,
            'status' => EmailAccountStatus::ACTIVE,
            'last_error' => null,
        ]);

        $account->user?->notify(new MailboxHistoryImportCompletedNotification($account->fresh() ?? $account));
    }
}
