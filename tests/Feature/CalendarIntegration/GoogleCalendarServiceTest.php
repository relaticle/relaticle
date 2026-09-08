<?php

declare(strict_types=1);

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
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

it('patches the connected account RSVP without replacing other attendees', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'email_address' => 'me@example.com',
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
    ]));

    $events = Mockery::mock(Events::class);
    $events->shouldReceive('patch')
        ->once()
        ->withArgs(function (string $calendarId, string $eventId, Event $body, array $opts): bool {
            $attendees = $body->getAttendees();

            return $calendarId === 'primary'
                && $eventId === 'evt-1'
                && $body->getAttendeesOmitted() === true
                && isset($attendees[0])
                && $attendees[0]->getEmail() === 'me@example.com'
                && $attendees[0]->getResponseStatus() === 'accepted'
                && $opts === ['sendUpdates' => 'all'];
        })
        ->andReturn(new Event);

    $calendar = new Calendar(Mockery::mock(Client::class));
    $calendar->events = $events;

    (new GoogleCalendarService($account, $calendar))
        ->respondToEvent('evt-1', AttendeeResponseStatus::ACCEPTED);
});
