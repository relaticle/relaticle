<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Data\SubscriberData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptionsWithRedis;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\MailcoachSdk\Facades\Mailcoach;

final class UpdateSubscriberJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly SubscriberData $data) {}

    public function handle(): void
    {
        $email = $this->data->email;
        $subscriber = Mailcoach::findByEmail(config('mailcoach-sdk.subscribers_list_id'), $this->data->email);

        if ($subscriber instanceof \Spatie\MailcoachSdk\Resources\Subscriber) {
            $data = $this->data->toArray();
            $data['tags'] = collect($subscriber->tags)->merge($this->data->tags)->unique()->toArray();

            Mailcoach::updateSubscriber($subscriber->uuid, array_filter($data));
        } else {
            Log::channel('email_subscriptions_channel')->info("Subscriber with email '$email' was not found!");
        }
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return $this->data->email;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<\Illuminate\Queue\Middleware\ThrottlesExceptionsWithRedis>
     */
    public function middleware(): array
    {
        return [new ThrottlesExceptionsWithRedis(1, 1)->backoff(1)->report()];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHour();
    }
}
