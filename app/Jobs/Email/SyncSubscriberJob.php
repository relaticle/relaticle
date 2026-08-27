<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Data\SubscriberData;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\MailcoachSdk\Facades\Mailcoach;
use Spatie\MailcoachSdk\Resources\Subscriber;

#[Tries(5)]
#[Backoff(60, 300, 900, 3600)]
final class SyncSubscriberJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Bounds the uniqueness lock. Completion and failure both release it, but a
     * worker killed mid-retry (deploy, OOM) would otherwise hold it forever and
     * permanently block this email from ever syncing again. Must outlast the
     * retry span above (~81 minutes) so dedup still holds across the chain.
     */
    public int $uniqueFor = 5400;

    public function __construct(private readonly SubscriberData $data) {}

    public function handle(): void
    {
        $listId = config('mailcoach-sdk.subscribers_list_id');
        $subscriber = Mailcoach::findByEmail($listId, $this->data->email);

        if ($subscriber instanceof Subscriber) {
            $this->updateExisting($subscriber);
        } else {
            $this->createNew($listId);
        }
    }

    private function updateExisting(Subscriber $subscriber): void
    {
        $this->storeUuidIfNeeded($subscriber->uuid);

        $data = $this->data->except('user_id')->toArray();
        // Tags are merged (additive), never replaced. Tag removal is handled explicitly
        // via ModifySubscriberTagsJob so that event-driven tags set elsewhere (manual
        // Mailcoach UI edits, other observers) are never clobbered by a profile sync.
        $data['tags'] = collect($subscriber->tags)->merge($this->data->tags)->unique()->toArray();

        Mailcoach::updateSubscriber($subscriber->uuid, array_filter($data));
    }

    private function createNew(string $listId): void
    {
        $subscriber = Mailcoach::createSubscriber(
            $listId,
            $this->data->except('user_id')->toArray(),
        );

        $this->storeUuidIfNeeded($subscriber->uuid);
    }

    private function storeUuidIfNeeded(string $uuid): void
    {
        if ($this->data->user_id) {
            User::query()
                ->where('id', $this->data->user_id)
                ->whereNull('mailcoach_subscriber_uuid')
                ->update(['mailcoach_subscriber_uuid' => $uuid]);
        }
    }

    public function uniqueId(): string
    {
        return $this->data->email;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Failed to sync subscriber '{$this->data->email}'", [
            'error' => $exception->getMessage(),
        ]);
    }
}
