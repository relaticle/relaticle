<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\MarkEmailsSendFailedAction;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Actions\SyncEmailBatchCountersAction;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Jobs\Concerns\ReleasesOnProviderRateLimit;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;
use Relaticle\EmailIntegration\Services\EmailSendingService;
use Throwable;

#[Backoff(30)]
#[Timeout(self::TIMEOUT_SECONDS)]
#[Tries(3)]
final class SendEmailJob implements ShouldQueue
{
    use Queueable;
    use ReleasesOnProviderRateLimit;

    private const int TIMEOUT_SECONDS = 120;

    public function __construct(
        public readonly string $emailId,
    ) {}

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->emailId)
                ->dontRelease()
                ->expireAfter(self::TIMEOUT_SECONDS + 60),
        ];
    }

    public function handle(EmailSendingService $sendingService, LinkEmailAction $linkEmailAction): void
    {
        $lock = Cache::lock($this->deliveryLockKey(), self::TIMEOUT_SECONDS + 60);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->deliver($sendingService, $linkEmailAction);
        } finally {
            $lock->release();
        }
    }

    private function deliveryLockKey(): string
    {
        return 'send-email:'.$this->emailId;
    }

    private function deliver(EmailSendingService $sendingService, LinkEmailAction $linkEmailAction): void
    {
        $email = $this->claimedEmail();

        if (! $email instanceof Email) {
            $existing = Email::query()->withoutGlobalScope(ActiveAccountScope::class)->find($this->emailId);
            $this->syncBatchCounters($existing?->batch_id);

            return;
        }

        if ($email->status !== EmailStatus::SENT) {
            try {
                $email = $sendingService->send($email);
            } catch (Throwable $exception) {
                if ($this->releaseIfProviderRateLimited((string) $email->connected_account_id, $exception)) {
                    return;
                }

                throw $exception;
            }

            $this->syncBatchCounters($email->batch_id);
        }

        $linkEmailAction->execute($email);

        if ($email->conversation_key !== null) {
            resolve(RefreshConversationSummary::class)->execute($email->workspace_id, $email->conversation_key);
        }

        $this->syncBatchCounters($email->batch_id);
    }

    private function claimedEmail(): ?Email
    {
        return DB::transaction(function (): ?Email {
            /** @var Email|null $lockedEmail */
            $lockedEmail = Email::query()->withoutGlobalScope(ActiveAccountScope::class)->lockForUpdate()->find($this->emailId);

            if ($lockedEmail === null) {
                return null;
            }

            if ($lockedEmail->status === EmailStatus::SENT) {
                return $lockedEmail;
            }

            // Accept any non-terminal state. The dispatcher claims QUEUED → SENDING
            // before enqueuing, so first attempts arrive here as SENDING; Laravel
            // retries of the same job also arrive as SENDING.
            if (! in_array($lockedEmail->status, [EmailStatus::QUEUED, EmailStatus::SENDING], true)) {
                return null;
            }

            if ($lockedEmail->connectedAccount?->isSendable() !== true) {
                resolve(MarkEmailsSendFailedAction::class)->execute(
                    collect([$lockedEmail]),
                    __('filament/notifications/email-send-failed.reasons.mailbox_needs_reconnect'),
                );

                return null;
            }

            $lockedEmail->update([
                'status' => EmailStatus::SENDING,
                'attempts' => $lockedEmail->attempts + 1,
            ]);

            return $lockedEmail;
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SendEmailJob failed', [
            'email_id' => $this->emailId,
            'exception' => $exception,
        ]);

        /** @var Email|null $email */
        $email = Email::query()->withoutGlobalScope(ActiveAccountScope::class)->find($this->emailId);

        if ($email === null) {
            return;
        }

        resolve(MarkEmailsSendFailedAction::class)->execute(
            collect([$email]),
            $exception::class.': '.$exception->getMessage(),
        );
    }

    private function syncBatchCounters(?string $batchId): void
    {
        resolve(SyncEmailBatchCountersAction::class)->execute($batchId);
    }
}
