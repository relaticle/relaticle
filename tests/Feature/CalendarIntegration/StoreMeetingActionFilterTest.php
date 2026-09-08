<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Actions\StoreMeetingAction;
use Relaticle\EmailIntegration\Data\NormalizedMeetingPayload;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Enums\CalendarVisibility;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;

mutates(StoreMeetingAction::class);

function payload(array $overrides = []): NormalizedMeetingPayload
{
    return new NormalizedMeetingPayload(
        providerEventId: $overrides['providerEventId'] ?? 'evt-'.fake()->uuid(),
        providerRecurringEventId: $overrides['providerRecurringEventId'] ?? null,
        icalUid: null,
        title: 'Quarterly Sync',
        description: null,
        location: null,
        startsAt: $overrides['startsAt'] ?? Carbon::now()->addDay(),
        endsAt: $overrides['endsAt'] ?? Carbon::now()->addDay()->addHour(),
        allDay: false,
        organizerEmail: 'host@example.com',
        organizerName: 'Host',
        status: $overrides['status'] ?? CalendarEventStatus::CONFIRMED,
        visibility: $overrides['visibility'] ?? CalendarVisibility::DEFAULT,
        selfResponseStatus: $overrides['selfResponseStatus'] ?? AttendeeResponseStatus::ACCEPTED,
        htmlLink: 'https://calendar.google.com/event?eid=abc',
        attendees: [],
    );
}

it('skips private events', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    (app(StoreMeetingAction::class))->execute(payload(['visibility' => CalendarVisibility::PRIVATE]), $account);

    expect(Meeting::query()->count())->toBe(0);
});

it('skips cancelled events', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    (app(StoreMeetingAction::class))->execute(payload(['status' => CalendarEventStatus::CANCELLED]), $account);

    expect(Meeting::query()->count())->toBe(0);
});

it('soft-deletes existing meeting when it is cancelled in the provider calendar', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $existing = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'team_id' => $account->team_id,
        'provider_event_id' => 'evt-cancelled',
    ]);

    (app(StoreMeetingAction::class))->execute(payload([
        'providerEventId' => 'evt-cancelled',
        'status' => CalendarEventStatus::CANCELLED,
    ]), $account);

    expect($existing->fresh()?->trashed())->toBeTrue();
});

it('soft-deletes only one occurrence when a single recurring instance is cancelled', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $first = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'team_id' => $account->team_id,
        'provider_event_id' => 'series_20250908',
        'provider_recurring_event_id' => 'series-master',
    ]);
    $second = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'team_id' => $account->team_id,
        'provider_event_id' => 'series_20250909',
        'provider_recurring_event_id' => 'series-master',
    ]);

    (app(StoreMeetingAction::class))->execute(payload([
        'providerEventId' => 'series_20250908',
        'providerRecurringEventId' => 'series-master',
        'status' => CalendarEventStatus::CANCELLED,
    ]), $account);

    expect($first->fresh()?->trashed())->toBeTrue()
        ->and($second->fresh()?->trashed())->toBeFalse();
});

it('soft-deletes only one occurrence when a single recurring instance becomes private', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $first = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'team_id' => $account->team_id,
        'provider_event_id' => 'series_20250908',
        'provider_recurring_event_id' => 'series-master',
    ]);
    $second = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'team_id' => $account->team_id,
        'provider_event_id' => 'series_20250909',
        'provider_recurring_event_id' => 'series-master',
    ]);

    (app(StoreMeetingAction::class))->execute(payload([
        'providerEventId' => 'series_20250908',
        'providerRecurringEventId' => 'series-master',
        'visibility' => CalendarVisibility::PRIVATE,
    ]), $account);

    expect($first->fresh()?->trashed())->toBeTrue()
        ->and($second->fresh()?->trashed())->toBeFalse();
});

it('soft-deletes all meetings in a recurring series when the series master is cancelled', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $first = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'team_id' => $account->team_id,
        'provider_event_id' => 'series_20250908',
        'provider_recurring_event_id' => 'series-master',
    ]);
    $second = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'team_id' => $account->team_id,
        'provider_event_id' => 'series_20250909',
        'provider_recurring_event_id' => 'series-master',
    ]);

    (app(StoreMeetingAction::class))->execute(payload([
        'providerEventId' => 'series-master',
        'providerRecurringEventId' => null,
        'status' => CalendarEventStatus::CANCELLED,
    ]), $account);

    expect($first->fresh()?->trashed())->toBeTrue()
        ->and($second->fresh()?->trashed())->toBeTrue();
});

it('stores events declined by self so RSVP can still be changed', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    (app(StoreMeetingAction::class))->execute(payload(['selfResponseStatus' => AttendeeResponseStatus::DECLINED]), $account);

    expect(Meeting::query()->count())->toBe(1)
        ->and(Meeting::query()->first()?->response_status)->toBe(AttendeeResponseStatus::DECLINED);
});

it('defaults a host meeting with no self response to accepted', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'host@example.com',
    ]));

    (app(StoreMeetingAction::class))->execute(new NormalizedMeetingPayload(
        providerEventId: 'evt-host-default',
        providerRecurringEventId: null,
        icalUid: null,
        title: 'Call',
        description: null,
        location: null,
        startsAt: Carbon::now()->addDay(),
        endsAt: Carbon::now()->addDay()->addHour(),
        allDay: false,
        organizerEmail: 'host@example.com',
        organizerName: 'Host',
        status: CalendarEventStatus::CONFIRMED,
        visibility: CalendarVisibility::DEFAULT,
        selfResponseStatus: null,
        htmlLink: null,
        attendees: [],
    ), $account);

    expect(Meeting::query()->first()?->response_status)->toBe(AttendeeResponseStatus::ACCEPTED);
});

it('keeps a host RSVP when the next sync has no self response', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'host@example.com',
    ]));

    $first = new NormalizedMeetingPayload(
        providerEventId: 'evt-host-keep',
        providerRecurringEventId: null,
        icalUid: null,
        title: 'Call',
        description: null,
        location: null,
        startsAt: Carbon::now()->addDay(),
        endsAt: Carbon::now()->addDay()->addHour(),
        allDay: false,
        organizerEmail: 'host@example.com',
        organizerName: 'Host',
        status: CalendarEventStatus::CONFIRMED,
        visibility: CalendarVisibility::DEFAULT,
        selfResponseStatus: AttendeeResponseStatus::TENTATIVE,
        htmlLink: null,
        attendees: [],
    );

    (app(StoreMeetingAction::class))->execute($first, $account);

    (app(StoreMeetingAction::class))->execute(new NormalizedMeetingPayload(
        providerEventId: 'evt-host-keep',
        providerRecurringEventId: null,
        icalUid: null,
        title: 'Call',
        description: null,
        location: null,
        startsAt: $first->startsAt,
        endsAt: $first->endsAt,
        allDay: false,
        organizerEmail: 'host@example.com',
        organizerName: 'Host',
        status: CalendarEventStatus::CONFIRMED,
        visibility: CalendarVisibility::DEFAULT,
        selfResponseStatus: null,
        htmlLink: null,
        attendees: [],
    ), $account);

    expect(Meeting::query()->where('provider_event_id', 'evt-host-keep')->first()?->response_status)
        ->toBe(AttendeeResponseStatus::TENTATIVE);
});

it('stores events older than 90 days', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    (app(StoreMeetingAction::class))->execute(payload([
        'startsAt' => Carbon::now()->subDays(100),
        'endsAt' => Carbon::now()->subDays(100)->addHour(),
    ]), $account);

    expect(Meeting::query()->count())->toBe(1);
});

it('soft-deletes existing meeting when it becomes private', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    $existing = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'team_id' => $account->team_id,
        'provider_event_id' => 'evt-xyz',
    ]);

    (app(StoreMeetingAction::class))->execute(payload([
        'providerEventId' => 'evt-xyz',
        'visibility' => CalendarVisibility::PRIVATE,
    ]), $account);

    expect($existing->fresh()?->trashed())->toBeTrue();
});

it('does not bump calendar import progress for a skipped private event', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());

    (app(StoreMeetingAction::class))->execute(payload(['visibility' => CalendarVisibility::PRIVATE]), $account);

    expect($account->fresh()?->initial_calendar_sync_imported)->toBe(0);
});
