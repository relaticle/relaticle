<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Exceptions\CalendarSyncTokenExpired;
use Relaticle\EmailIntegration\Exceptions\MeetingResponseFailed;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Factories\MicrosoftGraphClientFactory;
use Relaticle\EmailIntegration\Services\MicrosoftCalendarService;

mutates(MicrosoftCalendarService::class);

beforeEach(function (): void {
    config()->set('services.azure.client_id', 'azure-client-id');
    config()->set('services.azure.client_secret', 'azure-client-secret');
    config()->set('services.azure.tenant', 'common');

    // Prevent the ConnectedAccountObserver from dispatching sync jobs synchronously
    // during account creation, which would issue unfaked Graph requests.
    Bus::fake();
});

function makeAzureCalendarAccount(): ConnectedAccount
{
    $user = User::factory()->withTeam()->create();

    return ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'team_id' => $user->currentTeam->getKey(),
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'capabilities' => ['email' => true, 'calendar' => true],
        ]);
}

it('parses Graph calendarView/delta into CalendarEventData', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/calendarView/delta*' => Http::response([
            'value' => [
                [
                    'id' => 'evt-1',
                    'iCalUId' => 'ical-1',
                    'subject' => 'Standup',
                    'bodyPreview' => 'daily',
                    'start' => ['dateTime' => '2026-06-01T09:00:00', 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => '2026-06-01T09:30:00', 'timeZone' => 'UTC'],
                    'location' => ['displayName' => 'Zoom'],
                    'webLink' => 'https://outlook/...',
                    'isCancelled' => false,
                    'organizer' => ['emailAddress' => ['address' => 'org@example.com', 'name' => 'Org']],
                    'attendees' => [
                        ['emailAddress' => ['address' => 'a@example.com', 'name' => 'A'], 'status' => ['response' => 'accepted']],
                    ],
                ],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=NEW',
        ]),
    ]);

    $result = new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->fetchDelta('https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=OLD');

    expect($result->events)->toHaveCount(1)
        ->and($result->events[0]->title)->toBe('Standup')
        ->and($result->events[0]->organizerEmail)->toBe('org@example.com')
        ->and($result->events[0]->attendees[0]['email'])->toBe('a@example.com')
        ->and($result->events[0]->attendees[0]['is_organizer'])->toBeFalse()
        ->and($result->nextSyncToken)->toContain('$deltatoken=NEW');
});

it('maps Graph attendee response codes to the canonical vocabulary', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/calendarView/delta*' => Http::response([
            'value' => [
                [
                    'id' => 'evt-1',
                    'subject' => 'Sync',
                    'start' => ['dateTime' => '2026-06-01T09:00:00', 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => '2026-06-01T09:30:00', 'timeZone' => 'UTC'],
                    'isCancelled' => false,
                    'organizer' => ['emailAddress' => ['address' => 'org@example.com', 'name' => 'Org']],
                    'attendees' => [
                        ['emailAddress' => ['address' => 'tent@example.com'], 'status' => ['response' => 'tentativelyAccepted']],
                        ['emailAddress' => ['address' => 'none@example.com'], 'status' => ['response' => 'notResponded']],
                        ['emailAddress' => ['address' => 'org@example.com'], 'status' => ['response' => 'organizer']],
                    ],
                ],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=NEW',
        ]),
    ]);

    $result = new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->fetchDelta('https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=OLD');

    // tentativelyAccepted -> tentative, notResponded -> needsAction (Google's vocab).
    // organizer is not an RSVP. Leave it empty so a host status chosen in Relaticle is kept.
    expect($result->events[0]->attendees[0]['response_status'])->toBe('tentative')
        ->and($result->events[0]->attendees[1]['response_status'])->toBe('needsAction')
        ->and($result->events[0]->attendees[2]['response_status'])->toBeNull();
});

it('maps Graph "personal" sensitivity to private so the event is treated as private', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/calendarView/delta*' => Http::response([
            'value' => [
                [
                    'id' => 'evt-1',
                    'subject' => 'Personal',
                    'start' => ['dateTime' => '2026-06-01T09:00:00', 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => '2026-06-01T09:30:00', 'timeZone' => 'UTC'],
                    'isCancelled' => false,
                    'sensitivity' => 'personal',
                    'organizer' => ['emailAddress' => ['address' => 'org@example.com', 'name' => 'Org']],
                    'attendees' => [],
                ],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=NEW',
        ]),
    ]);

    $result = new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->fetchDelta('https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=OLD');

    expect($result->events[0]->visibility)->toBe('private');
});

it('starts the initial calendar delta at 1990 rather than 90 days ago', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/calendarView/delta*' => Http::response([
            'value' => [],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=NEW',
        ]),
    ]);

    new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->initialSync();

    Http::assertSent(function (Request $request): bool {
        $url = urldecode((string) $request->url());

        return str_contains($url, '/me/calendarView/delta')
            && str_contains($url, 'startDateTime=1990-01-01')
            && ! str_contains($url, now()->subDays(90)->toIso8601String());
    });
});

it('returns one initial calendar page and does not follow nextLink', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/calendarView/delta*' => Http::sequence()
            ->push([
                'value' => [
                    [
                        'id' => 'evt-1',
                        'subject' => 'One',
                        'start' => ['dateTime' => '2026-06-01T09:00:00', 'timeZone' => 'UTC'],
                        'end' => ['dateTime' => '2026-06-01T09:30:00', 'timeZone' => 'UTC'],
                        'isCancelled' => false,
                        'organizer' => ['emailAddress' => ['address' => 'org@example.com']],
                        'attendees' => [],
                    ],
                ],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$skiptoken=NEXT',
            ])
            ->push([
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=NEW',
            ]),
    ]);

    $result = new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->initialSync();

    expect($result->events)->toHaveCount(1)
        ->and(urldecode((string) $result->nextPageToken))->toContain('$skiptoken=NEXT')
        ->and($result->nextSyncToken)->toBeNull();

    Http::assertSentCount(1);
});

it('throws CalendarSyncTokenExpired on Graph 410', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/calendarView/delta*' => Http::response('', 410),
    ]);

    expect(fn (): CalendarSyncResult => new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->fetchDelta('https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=EXPIRED'))
        ->toThrow(CalendarSyncTokenExpired::class);
});

it('posts accept to Graph so the organizer is notified', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/events/evt-1/accept' => Http::response(null, 202),
    ]);

    new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->respondToEvent('evt-1', AttendeeResponseStatus::ACCEPTED);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/me/events/evt-1/accept')
        && $request['sendResponse'] === true);
});

it('posts tentativelyAccept for maybe', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/events/evt-1/tentativelyAccept' => Http::response(null, 202),
    ]);

    new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->respondToEvent('evt-1', AttendeeResponseStatus::TENTATIVE);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/me/events/evt-1/tentativelyAccept'));
});

it('does not fail when Graph rejects the host responding to their own meeting', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/events/evt-1/tentativelyAccept' => Http::response([
            'error' => [
                'code' => 'ErrorInvalidRequest',
                'message' => 'Your request can\'t be completed. You can\'t respond to this meeting because you\'re the organizer.',
            ],
        ], 400),
    ]);

    (new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class)))
        ->respondToEvent('evt-1', AttendeeResponseStatus::TENTATIVE);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/me/events/evt-1/tentativelyAccept'));
});

it('still fails when Graph returns a server error that mentions organizer', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/events/evt-1/decline' => Http::response([
            'error' => [
                'code' => 'UnknownError',
                'message' => 'The organizer service is unavailable.',
            ],
        ], 500),
    ]);

    expect(fn () => (new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class)))
        ->respondToEvent('evt-1', AttendeeResponseStatus::DECLINED))
        ->toThrow(MeetingResponseFailed::class);
});

it('resolves this mailbox event id from a shared iCalendar UID', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/events*' => Http::response([
            'value' => [
                ['id' => 'evt-teammate-mailbox'],
            ],
        ]),
    ]);

    $eventId = (new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class)))
        ->findEventIdByICalUid("uid-with'-quote", now());

    expect($eventId)->toBe('evt-teammate-mailbox');

    Http::assertSent(function (Request $request): bool {
        $url = urldecode($request->url());

        return $request->method() === 'GET'
            && str_contains($url, '/me/events')
            && str_contains($url, "iCalUId eq 'uid-with''-quote'")
            && str_contains($url, '$select=id')
            && str_contains($url, '$top=1');
    });
});

it('returns null when Graph has no event for the iCalendar UID', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/events*' => Http::response([
            'value' => [],
        ]),
    ]);

    expect((new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class)))
        ->findEventIdByICalUid('missing', now()))
        ->toBeNull();
});

it('paginates listActiveProviderEventIds across nextLink pages and time windows', function (): void {
    $this->travelTo('2020-01-01 00:00:00');

    Http::fake(function (Request $request) {
        $url = urldecode($request->url());

        if (str_contains($url, 'startDateTime=1990-01-01') && ! str_contains($url, '$skiptoken=')) {
            return Http::response([
                'value' => [
                    ['id' => 'evt-window-1', 'isCancelled' => false],
                ],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$skiptoken=PAGE2',
            ]);
        }

        if (str_contains($url, '$skiptoken=PAGE2')) {
            return Http::response([
                'value' => [
                    ['id' => 'evt-window-1-page-2', 'isCancelled' => false],
                ],
            ]);
        }

        if (str_contains($url, 'startDateTime=1995-01-01')) {
            return Http::response([
                'value' => [
                    ['id' => 'evt-window-2', 'isCancelled' => false],
                ],
            ]);
        }

        return Http::response(['value' => []]);
    });

    $ids = (new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class)))
        ->listActiveProviderEventIds();

    expect($ids)->toContain('evt-window-1', 'evt-window-1-page-2', 'evt-window-2');
});

it('terminates initial calendar backfill at a fixed import boundary even when time passes between jobs', function (): void {
    $this->travelTo('2026-09-13 00:00:00');

    $importBoundary = now()->addYears(5);
    $finalWindowUrl = 'https://graph.microsoft.com/v1.0/me/calendarView/delta'
        .'?startDateTime='.rawurlencode('2021-09-13T00:00:00+00:00')
        .'&endDateTime='.rawurlencode($importBoundary->toIso8601String())
        .'&importBoundaryEndDateTime='.rawurlencode($importBoundary->toIso8601String());

    Http::fake([
        'https://graph.microsoft.com/v1.0/me/calendarView/delta*' => Http::response([
            'value' => [],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=NEW',
        ]),
    ]);

    $this->travelTo('2026-09-13 00:05:00');

    $result = new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->initialSync($finalWindowUrl);

    expect($result->nextPageToken)->toBeNull()
        ->and($result->nextSyncToken)->toContain('$deltatoken=NEW');

    Http::assertSentCount(1);
});

it('persists the import boundary on nextLink page tokens during initial sync', function (): void {
    $this->travelTo('2026-09-13 00:00:00');

    Http::fake([
        'https://graph.microsoft.com/v1.0/me/calendarView/delta*' => Http::response([
            'value' => [],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$skiptoken=NEXT',
        ]),
    ]);

    $result = new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->initialSync();

    expect(urldecode((string) $result->nextPageToken))
        ->toContain('importBoundaryEndDateTime=')
        ->toContain(now()->addYears(5)->toIso8601String());
});
