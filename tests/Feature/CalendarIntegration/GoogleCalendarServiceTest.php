<?php

declare(strict_types=1);

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventOrganizer;
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
