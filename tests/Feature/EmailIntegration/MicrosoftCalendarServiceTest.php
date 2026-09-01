<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired;
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
                    ],
                ],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=NEW',
        ]),
    ]);

    $result = new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->fetchDelta('https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=OLD');

    // tentativelyAccepted -> tentative, notResponded -> needsAction (Google's vocab).
    expect($result->events[0]->attendees[0]['response_status'])->toBe('tentative')
        ->and($result->events[0]->attendees[1]['response_status'])->toBe('needsAction');
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
        ->and($result->nextPageToken)->toContain('$skiptoken=NEXT')
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
