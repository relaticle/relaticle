<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Enums\SubscriberTagEnum;
use App\Models\User;
use App\Support\Email\SubscriberProfile;
use App\Support\Email\SubscriberProfileDeriver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\MailcoachSdk\Exceptions\RateLimited;
use Spatie\MailcoachSdk\Exceptions\ResourceNotFound;
use Spatie\MailcoachSdk\Facades\Mailcoach;
use Spatie\MailcoachSdk\Resources\Subscriber;

/**
 * Upserts the user's complete Mailcoach profile (identity and every
 * app-owned tag) derived from the database at execution time. Idempotent:
 * triggers and the reconcile sweep dispatch it freely; a stored profile hash
 * short-circuits unchanged syncs before any API call.
 */
#[Tries(5)]
#[Backoff(60, 300, 900, 3600)]
final class SyncSubscriberJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Bounds the uniqueness lock. Completion and failure both release it, but a
     * worker killed mid-retry (deploy, OOM) would otherwise hold it forever and
     * permanently block this user from ever syncing again. Must outlast the
     * retry span above (~81 minutes) so dedup still holds across the chain.
     */
    public int $uniqueFor = 5400;

    public function __construct(private readonly string $userId) {}

    /**
     * The single entry point for trigger paths: owns the feature-flag gate and
     * defers the push until the surrounding transaction commits.
     */
    public static function dispatchFor(string $userId): void
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        dispatch(new self($userId))->afterCommit();
    }

    public function handle(SubscriberProfileDeriver $deriver): void
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        $user = User::query()->with(['ownedTeams', 'teams'])->find($this->userId);

        if (! $user || $user->email_verified_at === null) {
            return;
        }

        $profile = $deriver->derive($user);

        if ($profile->matchesStored($user)) {
            return;
        }

        try {
            $subscriber = $this->resolveSubscriber($user, $profile);

            $uuid = $subscriber instanceof Subscriber
                ? $this->updateSubscriber($subscriber, $profile)
                : $this->createSubscriber($profile);
        } catch (RateLimited $exception) {
            $this->release(max($exception->retryAfter, 10));

            return;
        }

        $user->forceFill([
            'mailcoach_subscriber_uuid' => $uuid,
            'subscriber_profile_hash' => $profile->hash(),
        ])->saveQuietly();
    }

    /**
     * Resolution is uuid-first so an email change updates the existing
     * subscriber (keeping its engagement history) instead of creating a
     * duplicate. The exact-match guard covers the SDK's substring filter.
     */
    private function resolveSubscriber(User $user, SubscriberProfile $profile): ?Subscriber
    {
        if ($user->mailcoach_subscriber_uuid) {
            try {
                return Mailcoach::subscriber($user->mailcoach_subscriber_uuid);
            } catch (\Throwable $exception) {
                // Only a deleted subscriber falls through to the email lookup.
                // A typed catch reads as dead to PHPStan (the SDK lacks @throws).
                throw_unless($exception instanceof ResourceNotFound, $exception);
            }
        }

        $subscriber = Mailcoach::findByEmail($this->listId(), $profile->email);

        if ($subscriber instanceof Subscriber && strcasecmp($subscriber->email, $profile->email) === 0) {
            return $subscriber;
        }

        return null;
    }

    private function updateSubscriber(Subscriber $subscriber, SubscriberProfile $profile): string
    {
        Mailcoach::updateSubscriber($subscriber->uuid, [
            'email' => $profile->email,
            'first_name' => $profile->firstName,
            'last_name' => $profile->lastName,
            'tags' => $this->mergeForeignTags($subscriber->tags, $profile->tags),
        ]);

        return $subscriber->uuid;
    }

    private function createSubscriber(SubscriberProfile $profile): string
    {
        $subscriber = Mailcoach::createSubscriber($this->listId(), [
            'email' => $profile->email,
            'first_name' => $profile->firstName,
            'last_name' => $profile->lastName,
            'tags' => $profile->tags,
            'skip_confirmation' => true,
        ]);

        return $subscriber->uuid;
    }

    /**
     * Mailcoach replaces tags wholesale on update, so the payload must carry
     * hand-applied (foreign) tags forward alongside the derived set.
     *
     * @param  array<int, string>  $currentTags
     * @param  list<string>  $desiredTags
     * @return list<string>
     */
    private function mergeForeignTags(array $currentTags, array $desiredTags): array
    {
        $foreignTags = array_filter(
            $currentTags,
            fn (string $tag): bool => ! SubscriberTagEnum::isOwned($tag),
        );

        return array_values(array_unique([...$foreignTags, ...$desiredTags]));
    }

    private function listId(): string
    {
        return (string) config('mailcoach-sdk.subscribers_list_id');
    }

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Failed to sync subscriber profile for user {$this->userId}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
