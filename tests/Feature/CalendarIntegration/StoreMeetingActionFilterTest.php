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
        providerRecurringEventId: null,
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

it('skips events declined by self', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    (app(StoreMeetingAction::class))->execute(payload(['selfResponseStatus' => AttendeeResponseStatus::DECLINED]), $account);

    expect(Meeting::query()->count())->toBe(0);
});

it('skips events older than 90 days', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());
    (app(StoreMeetingAction::class))->execute(payload([
        'startsAt' => Carbon::now()->subDays(100),
        'endsAt' => Carbon::now()->subDays(100)->addHour(),
    ]), $account);

    expect(Meeting::query()->count())->toBe(0);
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
