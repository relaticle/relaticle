<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Relaticle\EmailIntegration\Actions\StoreMeetingAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Factories\NormalizedMeetingPayloadFactory;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

#[DeleteWhenMissingModels]
#[Backoff(5, 5, 5)]
#[Queue('emails-sync')]
#[Tries(3)]
final class StoreMeetingJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly CalendarEventData $event,
        public readonly ?int $calendarSyncGeneration = null,
    ) {}

    public function handle(
        StoreMeetingAction $store,
        NormalizedMeetingPayloadFactory $factory,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->calendarSyncGeneration !== null
            && ! MailboxSyncTracker::isCalendarSyncGenerationCurrent($this->connectedAccount, $this->calendarSyncGeneration)) {
            return;
        }

        $payload = $factory->fromCalendarEvent($this->event, $this->connectedAccount->email_address);

        $store->execute($payload, $this->connectedAccount);
    }
}
