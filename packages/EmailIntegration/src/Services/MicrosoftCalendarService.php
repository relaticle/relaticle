<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired;
use Relaticle\EmailIntegration\Services\Factories\MicrosoftGraphClientFactory;

final readonly class MicrosoftCalendarService implements CalendarServiceInterface
{
    public function __construct(
        private ConnectedAccount $account,
        private MicrosoftGraphClientFactory $clientFactory,
    ) {}

    public function initialSync(): CalendarSyncResult
    {
        $start = now()->subDays(90)->toIso8601String();
        $end = now()->addDays(180)->toIso8601String();

        $url = '/me/calendarView/delta?startDateTime='.rawurlencode($start).'&endDateTime='.rawurlencode($end);

        return $this->drain($url);
    }

    public function fetchDelta(string $syncToken): CalendarSyncResult
    {
        return $this->drain($syncToken);
    }

    private function drain(string $url): CalendarSyncResult
    {
        $http = $this->clientFactory->make($this->account);

        $events = [];
        $deltaLink = null;

        do {
            try {
                $response = $http->get($url)->throw()->json();
            } catch (RequestException $e) {
                if ($e->response->status() === 410) {
                    throw CalendarSyncTokenExpired::forAccount($this->account->getKey());
                }

                throw $e;
            }

            foreach ($response['value'] ?? [] as $event) {
                // Graph delta emits tombstones for deleted events; they have no payload to
                // normalize, so surface them as cancelled to soft-delete the stored meeting.
                $events[] = isset($event['@removed'])
                    ? $this->tombstone((string) ($event['id'] ?? ''))
                    : $this->normalize($event);
            }

            $url = $response['@odata.nextLink'] ?? null;
            $deltaLink = $response['@odata.deltaLink'] ?? $deltaLink;
        } while ($url !== null);

        return new CalendarSyncResult(events: $events, nextSyncToken: $deltaLink);
    }

    private function tombstone(string $eventId): CalendarEventData
    {
        $now = Date::now();

        return new CalendarEventData(
            providerEventId: $eventId,
            providerRecurringEventId: null,
            iCalUid: null,
            title: null,
            description: null,
            startsAt: $now,
            endsAt: $now,
            isAllDay: false,
            location: null,
            htmlLink: null,
            status: 'cancelled',
            visibility: null,
            organizerEmail: null,
            organizerName: null,
            attendees: [],
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function normalize(array $event): CalendarEventData
    {
        $startsAt = Date::parse(
            (string) ($event['start']['dateTime'] ?? ''),
            (string) ($event['start']['timeZone'] ?? 'UTC'),
        );
        $endsAt = Date::parse(
            (string) ($event['end']['dateTime'] ?? ''),
            (string) ($event['end']['timeZone'] ?? 'UTC'),
        );

        $organizerEmail = $event['organizer']['emailAddress']['address'] ?? null;

        $attendees = [];
        foreach ($event['attendees'] ?? [] as $attendee) {
            $attendeeEmail = strtolower((string) ($attendee['emailAddress']['address'] ?? ''));
            $attendees[] = [
                'email' => $attendeeEmail,
                'name' => $attendee['emailAddress']['name'] ?? null,
                'response_status' => $this->mapResponseStatus($attendee['status']['response'] ?? null),
                'is_organizer' => $organizerEmail !== null
                    && strtolower((string) $organizerEmail) === $attendeeEmail,
            ];
        }

        return new CalendarEventData(
            providerEventId: (string) $event['id'],
            providerRecurringEventId: $event['seriesMasterId'] ?? null,
            iCalUid: $event['iCalUId'] ?? null,
            title: $event['subject'] ?? null,
            description: $event['bodyPreview'] ?? null,
            startsAt: $startsAt,
            endsAt: $endsAt,
            isAllDay: (bool) ($event['isAllDay'] ?? false),
            location: $event['location']['displayName'] ?? null,
            htmlLink: $event['webLink'] ?? null,
            status: ($event['isCancelled'] ?? false) ? 'cancelled' : 'confirmed',
            visibility: $this->mapSensitivity($event['sensitivity'] ?? null),
            organizerEmail: $organizerEmail,
            organizerName: $event['organizer']['emailAddress']['name'] ?? null,
            attendees: $attendees,
        );
    }

    /**
     * Translate Microsoft Graph attendee response codes into the canonical
     * AttendeeResponseStatus vocabulary (Google's), so downstream tryFrom() resolves.
     * Graph emits: none, organizer, tentativelyAccepted, accepted, declined, notResponded.
     */
    private function mapResponseStatus(?string $response): ?string
    {
        return match ($response) {
            'accepted' => 'accepted',
            'declined' => 'declined',
            'tentativelyAccepted' => 'tentative',
            // The organizer implicitly accepts their own meeting.
            'organizer' => 'accepted',
            'none', 'notResponded' => 'needsAction',
            default => null,
        };
    }

    /**
     * Translate Microsoft Graph sensitivity into the canonical CalendarVisibility
     * vocabulary. Graph emits: normal, personal, private, confidential. 'personal'
     * must map to private so personal events are treated as private (and skipped),
     * not silently exposed as the public DEFAULT.
     */
    private function mapSensitivity(?string $sensitivity): ?string
    {
        return match ($sensitivity) {
            'normal' => 'default',
            'personal', 'private' => 'private',
            'confidential' => 'confidential',
            default => null,
        };
    }
}
