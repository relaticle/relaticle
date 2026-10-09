<?php

declare(strict_types=1);

use App\Models\People;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Actions\AutoCreatePersonAction;
use Relaticle\EmailIntegration\Actions\StoreMeetingAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Factories\NormalizedMeetingPayloadFactory;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

mutates(StoreMeetingJob::class, NormalizedMeetingPayloadFactory::class, AutoCreatePersonAction::class);

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

it('stores calendar values longer than 255 characters', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create(['email_address' => 'me@example.com']));
    $long = str_repeat('b', 300);

    $event = new CalendarEventData(
        providerEventId: $long,
        providerRecurringEventId: $long,
        iCalUid: $long,
        title: $long,
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: $long,
        htmlLink: "https://outlook.office365.com/owa/?itemid={$long}",
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: "{$long}@external.test",
        organizerName: $long,
        attendees: [
            ['email' => 'me@example.com', 'name' => $long, 'response_status' => 'accepted', 'is_organizer' => false],
            ['email' => "{$long}@guest.test", 'name' => null, 'response_status' => 'accepted', 'is_organizer' => false],
        ],
    );

    (new StoreMeetingJob($account, $event))->handle(
        app(StoreMeetingAction::class),
        app(NormalizedMeetingPayloadFactory::class),
    );

    resolve(CurrentWorkspace::class)->set($account->workspace);

    $meeting = Meeting::query()->where('provider_event_id', $long)->sole();

    expect($meeting->title)->toBe($long)
        ->and($meeting->location)->toBe($long)
        ->and($meeting->organizer_email)->toBe("{$long}@external.test")
        ->and($meeting->attendees()->value('name'))->toBe($long)
        ->and(People::query()->where('workspace_id', $account->workspace_id)->pluck('name')->all())
        ->not->toBeEmpty()
        ->each->toHaveLength(255);
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

it('does not restore a cancelled meeting when the store job belongs to a superseded sync run', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'me@example.com',
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'valid-token',
    ]));

    $meeting = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'workspace_id' => $account->workspace_id,
        'provider_event_id' => 'evt-cancelled',
    ]);
    $meeting->delete();

    MailboxSyncTracker::markCalendarStarted($account);
    $staleGeneration = MailboxSyncTracker::currentCalendarSyncGeneration($account);

    MailboxSyncTracker::markCalendarFinished($account);
    MailboxSyncTracker::markCalendarStarted($account);

    $event = new CalendarEventData(
        providerEventId: 'evt-cancelled',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Was cancelled',
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
        attendees: [],
    );

    (new StoreMeetingJob($account, $event, $staleGeneration))->handle(
        app(StoreMeetingAction::class),
        app(NormalizedMeetingPayloadFactory::class),
    );

    expect($meeting->fresh()?->trashed())->toBeTrue();
});
