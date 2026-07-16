<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Actions\StoreMeetingAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Factories\NormalizedMeetingPayloadFactory;

mutates(StoreMeetingJob::class);

it('stores a calendar event via StoreMeetingJob', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create(['email_address' => 'me@example.com']));

    $event = new CalendarEventData(
        providerEventId: 'evt-123',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Kickoff',
        description: null,
        startsAt: Carbon::now()->addDay(),
        endsAt: Carbon::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [
            ['email' => 'me@example.com', 'name' => null, 'response_status' => 'accepted', 'is_organizer' => false],
        ],
    );

    (new StoreMeetingJob($account, $event))->handle(
        app(StoreMeetingAction::class),
        app(NormalizedMeetingPayloadFactory::class),
    );

    expect(Meeting::query()->where('provider_event_id', 'evt-123')->exists())->toBeTrue();
});
