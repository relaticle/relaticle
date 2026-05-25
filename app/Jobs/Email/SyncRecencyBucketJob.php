<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptionsWithRedis;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\MailcoachSdk\Facades\Mailcoach;

final class SyncRecencyBucketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        private readonly string $userId,
        private readonly string $subscriberUuid,
        private readonly ?string $oldBucket,
        private readonly ?string $newBucket,
    ) {}

    public function handle(): void
    {
        if ($this->oldBucket !== null) {
            Mailcoach::delete("subscribers/{$this->subscriberUuid}/tags", ['tags' => [$this->oldBucket]]);
        }

        if ($this->newBucket !== null) {
            Mailcoach::post("subscribers/{$this->subscriberUuid}/tags", ['tags' => [$this->newBucket]]);
        }

        User::query()
            ->whereKey($this->userId)
            ->update(['subscriber_recency_bucket' => $this->newBucket]);
    }

    /** @return array<ThrottlesExceptionsWithRedis> */
    public function middleware(): array
    {
        return [new ThrottlesExceptionsWithRedis(1, 1)->backoff(1)->report()];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHour();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Failed to sync recency bucket for subscriber {$this->subscriberUuid}", [
            'old' => $this->oldBucket,
            'new' => $this->newBucket,
            'error' => $exception->getMessage(),
        ]);
    }
}
