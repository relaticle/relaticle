<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\EmailIntegration\Actions\StoreMeetingAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Factories\NormalizedMeetingPayloadFactory;

#[DeleteWhenMissingModels]
final class StoreMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly CalendarEventData $event,
    ) {
        $this->onQueue('emails-sync');
    }

    public function handle(
        StoreMeetingAction $store,
        NormalizedMeetingPayloadFactory $factory,
    ): void {
        $payload = $factory->fromCalendarEvent($this->event, $this->connectedAccount->email_address);

        $store->execute($payload, $this->connectedAccount);
    }
}
