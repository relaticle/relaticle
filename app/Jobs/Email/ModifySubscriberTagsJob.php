<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Enums\TagAction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\MailcoachSdk\Facades\Mailcoach;

#[Tries(6)]
final class ModifySubscriberTagsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Doubles as the retry schedule and as the delay used while waiting for
     * SyncSubscriberJob to store the subscriber uuid, so a slow or failing
     * subscriber sync cannot drop a tag. Six tries means six waits, the last
     * two both clamped to the final delay, so the total window (~171 minutes)
     * deliberately outlasts SyncSubscriberJob's own budget (~81 minutes).
     *
     * @var list<int>
     */
    private const array RETRY_DELAYS = [60, 300, 900, 1800, 3600];

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        private readonly string $userId,
        private readonly array $tags,
        private readonly TagAction $action = TagAction::Add,
    ) {}

    public function handle(): void
    {
        $subscriberUuid = User::query()
            ->whereKey($this->userId)
            ->value('mailcoach_subscriber_uuid');

        if (! $subscriberUuid) {
            $this->release($this->nextDelay());

            return;
        }

        match ($this->action) {
            TagAction::Add => Mailcoach::post("subscribers/{$subscriberUuid}/tags", ['tags' => $this->tags]),
            TagAction::Remove => Mailcoach::delete("subscribers/{$subscriberUuid}/tags", ['tags' => $this->tags]),
        };
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return self::RETRY_DELAYS;
    }

    private function nextDelay(): int
    {
        return self::RETRY_DELAYS[min($this->attempts(), count(self::RETRY_DELAYS)) - 1];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Failed to {$this->action->value} tags for user {$this->userId}", [
            'tags' => $this->tags,
            'error' => $exception->getMessage(),
        ]);
    }
}
