<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Spatie\MailcoachSdk\Exceptions\RateLimited;
use Spatie\MailcoachSdk\Exceptions\ResourceNotFound;
use Spatie\MailcoachSdk\Facades\Mailcoach;
use Spatie\MailcoachSdk\Resources\Subscriber;

#[Tries(5)]
#[Backoff(60, 300, 900, 3600)]
final class DeleteSubscriberJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ?string $subscriberUuid,
        private readonly string $email,
    ) {}

    public static function dispatchFor(User $user): void
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        dispatch(new self($user->mailcoach_subscriber_uuid, $user->email))->afterCommit();
    }

    public function handle(): void
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        try {
            $uuid = $this->subscriberUuid ?? $this->findUuidByEmail();

            if ($uuid === null) {
                return;
            }

            Mailcoach::deleteSubscriber($uuid);
        } catch (RateLimited $exception) {
            $this->release(max($exception->retryAfter, 10));
        } catch (\Throwable $exception) {
            // A typed catch reads as dead to PHPStan (the SDK lacks @throws).
            throw_unless($exception instanceof ResourceNotFound, $exception);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to delete the Mailcoach subscriber of a deleted account', [
            'error' => $exception->getMessage(),
        ]);
    }

    private function findUuidByEmail(): ?string
    {
        $subscriber = Mailcoach::findByEmail((string) config('mailcoach-sdk.subscribers_list_id'), $this->email);

        if (! $subscriber instanceof Subscriber) {
            return null;
        }

        return strcasecmp($subscriber->email, $this->email) === 0 ? $subscriber->uuid : null;
    }
}
