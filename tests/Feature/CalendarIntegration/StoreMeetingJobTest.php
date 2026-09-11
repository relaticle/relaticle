<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Actions\StoreMeetingAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Factories\NormalizedMeetingPayloadFactory;

mutates(StoreMeetingJob::class, NormalizedMeetingPayloadFactory::class);

it('stores a calendar event via StoreMeetingJob', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create(['email_address' => 'me@example.com']));

    $event = new CalendarEventData(
        providerEventId: 'evt-123',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Kickoff',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
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

it('stores the mailbox owner as host when the provider omits them from attendees', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'me@example.com',
    ]));

    $event = new CalendarEventData(
        providerEventId: 'evt-host',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Call Asmit',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: 'me@example.com',
        organizerName: 'Asmit Magan',
        attendees: [
            ['email' => 'guest@example.com', 'name' => 'Guest', 'response_status' => 'needsAction', 'is_organizer' => false],
        ],
    );

    (new StoreMeetingJob($account, $event))->handle(
        app(StoreMeetingAction::class),
        app(NormalizedMeetingPayloadFactory::class),
    );

    $meeting = Meeting::query()->where('provider_event_id', 'evt-host')->firstOrFail();

    expect($meeting->response_status)->toBe(AttendeeResponseStatus::ACCEPTED)
        ->and($meeting->attendees()->where('is_self', true)->first()?->is_organizer)->toBeTrue();
});

it('does not duplicate a host who is already in attendees without the organizer flag', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'guest@example.com',
    ]));

    $event = new CalendarEventData(
        providerEventId: 'evt-listed-host',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Listed host',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: 'host@example.com',
        organizerName: 'Host',
        attendees: [
            ['email' => 'host@example.com', 'name' => 'Host', 'response_status' => 'accepted', 'is_organizer' => false],
            ['email' => 'guest@example.com', 'name' => 'Guest', 'response_status' => 'accepted', 'is_organizer' => false],
        ],
    );

    (new StoreMeetingJob($account, $event))->handle(
        app(StoreMeetingAction::class),
        app(NormalizedMeetingPayloadFactory::class),
    );

    $meeting = Meeting::query()->where('provider_event_id', 'evt-listed-host')->firstOrFail();

    expect($meeting->attendees()->where('email_address', 'host@example.com')->count())->toBe(1);
});
