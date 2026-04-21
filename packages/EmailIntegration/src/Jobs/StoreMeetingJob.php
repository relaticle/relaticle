<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Google\Service\Calendar\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\EmailIntegration\Actions\StoreMeetingAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Factories\NormalizedMeetingPayloadFactory;

final class StoreMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly string $serializedEvent,
    ) {}

    public function handle(
        StoreMeetingAction $store,
        NormalizedMeetingPayloadFactory $factory,
    ): void {
        /** @var Event $event */
        $event = unserialize($this->serializedEvent, ['allowed_classes' => true]);

        $payload = $factory->fromGoogleEvent($event, $this->connectedAccount->email_address);

        $store->execute($payload, $this->connectedAccount);
    }
}
