<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Exceptions\CalendarPushChannelFailed;
use Relaticle\EmailIntegration\Services\Factories\MicrosoftGraphClientFactory;
use Relaticle\EmailIntegration\Support\CalendarPushWebhookUrl;
use Throwable;

#[DeleteWhenMissingModels]
final class EnsureCalendarPushChannelJob implements ShouldBeUnique, ShouldQueue
{
    use DetectsAuthErrors, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
    ) {
        $this->onQueue('emails-sync');
    }

    public function handle(CalendarServiceFactoryInterface $calendarFactory): void
    {
        $account = $this->connectedAccount->fresh();

        if (! $account instanceof ConnectedAccount) {
            return;
        }

        if (! $this->shouldEnsurePushChannel($account)) {
            return;
        }

        if ($this->renewMicrosoftSubscriptionIfNeeded($account)) {
            return;
        }

        $this->replacePushChannel($account, $calendarFactory);
    }

    public function uniqueId(): string
    {
        return 'ensure-calendar-push-'.$this->connectedAccount->getKey();
    }

    private function shouldEnsurePushChannel(ConnectedAccount $account): bool
    {
        if (! CalendarPushWebhookUrl::isPubliclyReachable()) {
            return false;
        }

        if (! $account->hasCalendar()
            || $account->status !== EmailAccountStatus::ACTIVE
            || $account->calendar_sync_cursor === null) {
            return false;
        }

        if ($account->calendar_push_expires_at !== null
            && $account->calendar_push_expires_at->isAfter(now()->addDay())) {
            return false;
        }

        return true;
    }

    private function renewMicrosoftSubscriptionIfNeeded(ConnectedAccount $account): bool
    {
        if ($account->provider !== EmailProvider::AZURE) {
            return false;
        }

        $subscriptionId = $account->calendar_push_channel_id;

        if ($subscriptionId === null || $subscriptionId === '') {
            return false;
        }

        $expiresAt = now()->addDays(2);

        try {
            $response = resolve(MicrosoftGraphClientFactory::class)
                ->make($account)
                ->patch('/subscriptions/'.rawurlencode($subscriptionId), [
                    'expirationDateTime' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
                ])
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            if ($exception instanceof RequestException && $exception->response->status() === 404) {
                return false;
            }

            throw CalendarPushChannelFailed::fromProvider($exception);
        }

        $expiration = $response['expirationDateTime'] ?? null;

        $account->update([
            'calendar_push_expires_at' => is_string($expiration) && $expiration !== ''
                ? Date::parse($expiration)
                : $expiresAt,
        ]);

        return true;
    }

    private function replacePushChannel(
        ConnectedAccount $account,
        CalendarServiceFactoryInterface $calendarFactory,
    ): void {
        $service = $calendarFactory->make($account);
        $verificationToken = Str::random(40);
        $webhookUrl = CalendarPushWebhookUrl::forProvider($account->provider->value);
        $oldChannelId = $account->calendar_push_channel_id;
        $oldResourceId = $account->calendar_push_resource_id;

        $channel = $service->ensurePushChannel($webhookUrl, $verificationToken);

        if ($channel === null) {
            throw CalendarPushChannelFailed::unableToCreate();
        }

        $account->update([
            'calendar_push_channel_id' => $channel->channelId,
            'calendar_push_resource_id' => $channel->resourceId,
            'calendar_push_verification_token' => $channel->verificationToken,
            'calendar_push_expires_at' => $channel->expiresAt,
        ]);

        if ($oldChannelId !== null && $oldChannelId !== $channel->channelId) {
            $service->stopPushChannel($oldChannelId, $oldResourceId);
        }
    }

    public function failed(Throwable $exception): void
    {
        $account = $this->connectedAccount->fresh();

        if (! $account instanceof ConnectedAccount) {
            return;
        }

        $account->update([
            'status' => $this->isAuthError($exception) ? EmailAccountStatus::REAUTH_REQUIRED : $account->status,
            'last_error' => 'Calendar push notifications could not be renewed: '.$exception->getMessage(),
        ]);
    }
}
