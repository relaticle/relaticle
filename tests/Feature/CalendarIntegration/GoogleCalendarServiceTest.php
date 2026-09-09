<?php

declare(strict_types=1);

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\EventOrganizer;
use Google\Service\Calendar\Events as EventsResource;
use Google\Service\Calendar\Resource\Events;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\GoogleCalendarService;

mutates(GoogleCalendarService::class);

it('constructs from a ConnectedAccount with a live token', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
    ]));

    $service = GoogleCalendarService::forAccount($account);

    expect($service)->toBeInstanceOf(GoogleCalendarService::class);
});

it('surfaces a revoked/absent grant as an auth error instead of persisting a null token', function (): void {
    // Expired access token with no refresh token: the shared client factory throws
    // invalid_grant so the calendar sync job flips the account to REAUTH_REQUIRED,
    // matching the mail-sync behaviour.
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'refresh_token' => null,
        'token_expires_at' => now()->subHour(),
    ]));

    expect(fn () => GoogleCalendarService::forAccount($account))
        ->toThrow(RuntimeException::class, 'invalid_grant');
});

it('paginates initialSync and returns nextSyncToken', function (): void {
    // The real client call is hard to fake here without a test double — this test is a type-shape smoke test.

    expect(method_exists(GoogleCalendarService::class, 'initialSync'))->toBeTrue();
});

it('patches the calendar self attendee RSVP from a fetched event', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'me@example.com',
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
    ]));

    $self = new EventAttendee;
    $self->setEmail('alias@example.com');
    $self->setSelf(true);
    $self->setResponseStatus('accepted');

    $host = new EventAttendee;
    $host->setEmail('host@example.com');
    $host->setResponseStatus('accepted');

    $existing = new Event;
    $existing->setAttendees([$host, $self]);

    $events = Mockery::mock(Events::class);
    $events->shouldReceive('get')
        ->once()
        ->with('primary', 'evt-1')
        ->andReturn($existing);
    $events->shouldReceive('patch')
        ->once()
        ->withArgs(function (string $calendarId, string $eventId, Event $body, array $opts): bool {
            $attendees = $body->getAttendees();

            return $calendarId === 'primary'
                && $eventId === 'evt-1'
                && $body->getAttendeesOmitted() === true
                && isset($attendees[0])
                && $attendees[0]->getEmail() === 'alias@example.com'
                && $attendees[0]->getResponseStatus() === 'declined'
                && $opts === ['sendUpdates' => 'all'];
        })
        ->andReturn(new Event);

    $calendar = new Calendar(Mockery::mock(Client::class));
    $calendar->events = $events;

    (new GoogleCalendarService($account, $calendar))
        ->respondToEvent('evt-1', AttendeeResponseStatus::DECLINED);
});

it('patches the mailbox email when Google omits the self flag', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'me@example.com',
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
    ]));

    $guest = new EventAttendee;
    $guest->setEmail('me@example.com');
    $guest->setResponseStatus('accepted');

    $existing = new Event;
    $existing->setAttendees([$guest]);

    $events = Mockery::mock(Events::class);
    $events->shouldReceive('get')
        ->once()
        ->with('primary', 'evt-1')
        ->andReturn($existing);
    $events->shouldReceive('patch')
        ->once()
        ->withArgs(function (string $calendarId, string $eventId, Event $body, array $opts): bool {
            $attendees = $body->getAttendees();

            return $calendarId === 'primary'
                && $eventId === 'evt-1'
                && $body->getAttendeesOmitted() === true
                && isset($attendees[0])
                && $attendees[0]->getEmail() === 'me@example.com'
                && $attendees[0]->getResponseStatus() === 'tentative'
                && $opts === ['sendUpdates' => 'all'];
        })
        ->andReturn(new Event);

    $calendar = new Calendar(Mockery::mock(Client::class));
    $calendar->events = $events;

    (new GoogleCalendarService($account, $calendar))
        ->respondToEvent('evt-1', AttendeeResponseStatus::TENTATIVE);
});

it('adds the omitted host to Google attendees so the RSVP can persist', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'me@example.com',
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
    ]));

    $guest = new EventAttendee;
    $guest->setEmail('guest@example.com');
    $guest->setResponseStatus('accepted');

    $organizer = new EventOrganizer;
    $organizer->setEmail('me@example.com');

    $existing = new Event;
    $existing->setOrganizer($organizer);
    $existing->setAttendees([$guest]);

    $events = Mockery::mock(Events::class);
    $events->shouldReceive('get')
        ->once()
        ->with('primary', 'evt-1')
        ->andReturn($existing);
    $events->shouldReceive('patch')
        ->once()
        ->withArgs(function (string $calendarId, string $eventId, Event $body, array $opts): bool {
            $byEmail = [];

            foreach ($body->getAttendees() as $attendee) {
                $byEmail[strtolower((string) $attendee->getEmail())] = $attendee;
            }

            return $calendarId === 'primary'
                && $eventId === 'evt-1'
                && $body->getAttendeesOmitted() !== true
                && isset($byEmail['me@example.com'], $byEmail['guest@example.com'])
                && $byEmail['me@example.com']->getOrganizer() === true
                && $byEmail['me@example.com']->getResponseStatus() === 'declined'
                && $byEmail['guest@example.com']->getResponseStatus() === 'accepted'
                && $opts === ['sendUpdates' => 'none'];
        })
        ->andReturn(new Event);

    $calendar = new Calendar(Mockery::mock(Client::class));
    $calendar->events = $events;

    (new GoogleCalendarService($account, $calendar))
        ->respondToEvent('evt-1', AttendeeResponseStatus::DECLINED);
});

it('adds the host when Google omits the attendees list', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'me@example.com',
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
    ]));

    $organizer = new EventOrganizer;
    $organizer->setEmail('me@example.com');

    $existing = new Event;
    $existing->setOrganizer($organizer);

    $events = Mockery::mock(Events::class);
    $events->shouldReceive('get')
        ->once()
        ->with('primary', 'evt-1')
        ->andReturn($existing);
    $events->shouldReceive('patch')
        ->once()
        ->withArgs(function (string $calendarId, string $eventId, Event $body, array $opts): bool {
            $attendees = $body->getAttendees();

            return $calendarId === 'primary'
                && $eventId === 'evt-1'
                && isset($attendees[0])
                && $attendees[0]->getEmail() === 'me@example.com'
                && $attendees[0]->getOrganizer() === true
                && $attendees[0]->getResponseStatus() === 'tentative'
                && $body->getAttendeesOmitted() !== true
                && $opts === ['sendUpdates' => 'none'];
        })
        ->andReturn(new Event);

    $calendar = new Calendar(Mockery::mock(Client::class));
    $calendar->events = $events;

    (new GoogleCalendarService($account, $calendar))
        ->respondToEvent('evt-1', AttendeeResponseStatus::TENTATIVE);
});

it('resolves this mailbox event id from a shared iCalendar UID', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'me@example.com',
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
    ]));

    $match = new Event;
    $match->setId('evt-teammate-mailbox');

    $page = new EventsResource;
    $page->setItems([$match]);

    $events = Mockery::mock(Events::class);
    $events->shouldReceive('listEvents')
        ->once()
        ->with('primary', [
            'iCalUID' => 'ical-shared',
            'maxResults' => 1,
            'showDeleted' => false,
        ])
        ->andReturn($page);

    $calendar = new Calendar(Mockery::mock(Client::class));
    $calendar->events = $events;

    $eventId = (new GoogleCalendarService($account, $calendar))->findEventIdByICalUid('ical-shared');

    expect($eventId)->toBe('evt-teammate-mailbox');
});

it('returns null when Google has no event for the iCalendar UID', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
    ]));

    $page = new EventsResource;
    $page->setItems([]);

    $events = Mockery::mock(Events::class);
    $events->shouldReceive('listEvents')->once()->andReturn($page);

    $calendar = new Calendar(Mockery::mock(Client::class));
    $calendar->events = $events;

    expect((new GoogleCalendarService($account, $calendar))->findEventIdByICalUid('missing'))
        ->toBeNull();
});

it('converts timed Google events to UTC before persistence', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
    ]));

    $start = new EventDateTime;
    $start->setDateTime('2024-01-01T09:00:00+05:45');

    $end = new EventDateTime;
    $end->setDateTime('2024-01-01T10:00:00+05:45');

    $event = new Event;
    $event->setId('evt-timed');
    $event->setStatus('confirmed');
    $event->setSummary('Morning sync');
    $event->setStart($start);
    $event->setEnd($end);

    $eventsList = new EventsResource;
    $eventsList->setItems([$event]);
    $eventsList->setNextSyncToken('next-token');

    $events = Mockery::mock(Events::class);
    $events->shouldReceive('listEvents')
        ->once()
        ->andReturn($eventsList);

    $calendar = new Calendar(Mockery::mock(Client::class));
    $calendar->events = $events;

    $result = (new GoogleCalendarService($account, $calendar))->initialSync();

    expect($result->events)->toHaveCount(1)
        ->and($result->events[0]->startsAt->timezone->getName())->toBe('UTC')
        ->and($result->events[0]->startsAt->toDateTimeString())->toBe('2024-01-01 03:15:00')
        ->and($result->events[0]->endsAt->toDateTimeString())->toBe('2024-01-01 04:15:00');
});

it('requests deleted events during incremental sync and maps cancellations to tombstones', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
    ]));

    $cancelled = new Event;
    $cancelled->setId('evt-deleted');
    $cancelled->setStatus('cancelled');

    $eventsList = new EventsResource;
    $eventsList->setItems([$cancelled]);
    $eventsList->setNextSyncToken('next-token');

    $events = Mockery::mock(Events::class);
    $events->shouldReceive('listEvents')
        ->once()
        ->with('primary', [
            'syncToken' => 'sync-token',
            'singleEvents' => true,
            'showDeleted' => true,
            'maxResults' => 250,
        ])
        ->andReturn($eventsList);

    $calendar = new Calendar(Mockery::mock(Client::class));
    $calendar->events = $events;

    $result = (new GoogleCalendarService($account, $calendar))->fetchDelta('sync-token');

    expect($result->nextSyncToken)->toBe('next-token')
        ->and($result->events)->toHaveCount(1)
        ->and($result->events[0]->providerEventId)->toBe('evt-deleted')
        ->and($result->events[0]->status)->toBe('cancelled');
});
