<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Enums\TagAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptionsWithRedis;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\MailcoachSdk\Facades\Mailcoach;

final class ModifySubscriberTagsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        private readonly string $subscriberUuid,
        private readonly array $tags,
        private readonly TagAction $action = TagAction::Add,
    ) {}

    public function handle(): void
    {
        match ($this->action) {
            TagAction::Add => Mailcoach::post("subscribers/{$this->subscriberUuid}/tags", ['tags' => $this->tags]),
            TagAction::Remove => Mailcoach::delete("subscribers/{$this->subscriberUuid}/tags", ['tags' => $this->tags]),
        };
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
        Log::error("Failed to {$this->action->value} tags for subscriber {$this->subscriberUuid}", [
            'tags' => $this->tags,
            'error' => $exception->getMessage(),
        ]);
    }
}
