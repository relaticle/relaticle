<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Models\EmailChallenge;
use App\Notifications\Auth\EmailCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Encrypted end to end; sends synchronously in handle() so the code never
 * hits the queue a second time unencrypted. Rechecks terminal state first.
 */
#[Backoff(30, 120)]
final class SendEmailChallenge implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $challengeId,
        private readonly string $code,
    ) {}

    public function handle(): void
    {
        $challenge = EmailChallenge::query()->find($this->challengeId);

        if (! $challenge instanceof EmailChallenge || $challenge->isTerminal()) {
            return;
        }

        Notification::route('mail', $challenge->email)
            ->notify(new EmailCode($this->code, $challenge->purpose, $challenge->purpose->lifetimeMinutes()));
    }

    /**
     * Bounds retries to the code's own remaining lifetime, not a fixed count.
     */
    public function retryUntil(): ?CarbonImmutable
    {
        return EmailChallenge::query()->find($this->challengeId)?->expires_at;
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Failed to deliver an email challenge code.', [
            'challenge_id' => $this->challengeId,
            'error' => $exception->getMessage(),
        ]);
    }
}
