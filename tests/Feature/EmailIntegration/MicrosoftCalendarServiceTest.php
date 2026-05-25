<?php

declare(strict_types=1);

use App\Models\User;
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

it('throws CalendarSyncTokenExpired on Graph 410', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/me/calendarView/delta*' => Http::response('', 410),
    ]);

    expect(fn (): CalendarSyncResult => new MicrosoftCalendarService(makeAzureCalendarAccount(), resolve(MicrosoftGraphClientFactory::class))
        ->fetchDelta('https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=EXPIRED'))
        ->toThrow(CalendarSyncTokenExpired::class);
});
