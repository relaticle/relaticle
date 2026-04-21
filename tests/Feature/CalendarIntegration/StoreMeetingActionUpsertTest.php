<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Actions\StoreMeetingAction;
use Relaticle\EmailIntegration\Data\NormalizedAttendee;
use Relaticle\EmailIntegration\Data\NormalizedMeetingPayload;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Enums\CalendarVisibility;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;

mutates(StoreMeetingAction::class);

it('creates a meeting with attendees', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());

    $payload = new NormalizedMeetingPayload(
        providerEventId: 'evt-1',
        providerRecurringEventId: null,
        icalUid: 'uid-1',
        title: 'Design review',
        description: 'Q2 planning',
        location: 'Zoom',
        startsAt: Carbon::now()->addDays(2),
        endsAt: Carbon::now()->addDays(2)->addHour(),
        allDay: false,
        organizerEmail: 'host@example.com',
        organizerName: 'Host',
        status: CalendarEventStatus::CONFIRMED,
        visibility: CalendarVisibility::DEFAULT,
        selfResponseStatus: AttendeeResponseStatus::ACCEPTED,
        htmlLink: 'https://calendar.google.com/abc',
        attendees: [
            new NormalizedAttendee('host@example.com', 'Host', AttendeeResponseStatus::ACCEPTED, true, false),
            new NormalizedAttendee('me@example.com', 'Me', AttendeeResponseStatus::ACCEPTED, false, true),
            new NormalizedAttendee('guest@acme.com', 'Guest', AttendeeResponseStatus::TENTATIVE, false, false),
        ],
    );

    (app(StoreMeetingAction::class))->execute($payload, $account);

    $meeting = Meeting::query()->where('provider_event_id', 'evt-1')->firstOrFail();
    expect($meeting->title)->toBe('Design review');
    expect($meeting->attendees()->count())->toBe(3);
    expect($meeting->attendees()->where('is_self', true)->first()?->email_address)->toBe('me@example.com');
});

it('updates an existing meeting idempotently', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $payload = new NormalizedMeetingPayload(
        providerEventId: 'evt-2',
        providerRecurringEventId: null, icalUid: null,
        title: 'First title', description: null, location: null,
        startsAt: Carbon::now()->addDay(), endsAt: Carbon::now()->addDay()->addHour(),
        allDay: false, organizerEmail: null, organizerName: null,
        status: CalendarEventStatus::CONFIRMED, visibility: CalendarVisibility::DEFAULT,
        selfResponseStatus: AttendeeResponseStatus::ACCEPTED, htmlLink: null, attendees: [],
    );
    (app(StoreMeetingAction::class))->execute($payload, $account);

    $updated = new NormalizedMeetingPayload(
        providerEventId: 'evt-2',
        providerRecurringEventId: null, icalUid: null,
        title: 'Second title', description: null, location: null,
        startsAt: $payload->startsAt, endsAt: $payload->endsAt,
        allDay: false, organizerEmail: null, organizerName: null,
        status: CalendarEventStatus::CONFIRMED, visibility: CalendarVisibility::DEFAULT,
        selfResponseStatus: AttendeeResponseStatus::ACCEPTED, htmlLink: null, attendees: [],
    );
    (app(StoreMeetingAction::class))->execute($updated, $account);

    expect(Meeting::query()->count())->toBe(1);
    expect(Meeting::query()->first()?->title)->toBe('Second title');
});
