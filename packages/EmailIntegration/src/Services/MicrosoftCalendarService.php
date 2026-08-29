<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired;
use Relaticle\EmailIntegration\Services\Factories\MicrosoftGraphClientFactory;

final readonly class MicrosoftCalendarService implements CalendarServiceInterface
{
    private const string CALENDAR_DELTA = '/me/calendarView/delta';

    private const int WINDOW_YEARS = 5;

    public function __construct(
        private ConnectedAccount $account,
        private MicrosoftGraphClientFactory $clientFactory,
    ) {}

    public function initialSync(?string $pageToken = null): CalendarSyncResult
    {
        $url = $pageToken ?? $this->calendarWindowUrl($this->historyStart());

        return $this->drainOnePage($url, isInitial: true);
    }

    public function fetchDelta(string $syncToken): CalendarSyncResult
    {
        return $this->drainOnePage($syncToken, isInitial: false);
    }

    /**
     * @param  bool  $isInitial  When true, stop after one HTTP page and chain remaining windows via nextPageToken
     */
    private function drainOnePage(string $url, bool $isInitial): CalendarSyncResult
    {
        $http = $this->clientFactory->make($this->account);

        try {
            $response = $http->get($url)->throw()->json();
        } catch (RequestException $e) {
            if ($e->response->status() === 410) {
                throw CalendarSyncTokenExpired::forAccount($this->account->getKey());
            }

            throw $e;
        }

        $events = [];

        foreach ($response['value'] ?? [] as $event) {
            $events[] = isset($event['@removed'])
                ? $this->tombstone((string) ($event['id'] ?? ''))
                : $this->normalize($event);
        }

        $nextLink = $response['@odata.nextLink'] ?? null;
        $deltaLink = $response['@odata.deltaLink'] ?? null;

        if (! $isInitial) {
            $follow = $nextLink;

            while (is_string($follow) && $follow !== '') {
                try {
                    $response = $http->get($follow)->throw()->json();
                } catch (RequestException $e) {
                    if ($e->response->status() === 410) {
                        throw CalendarSyncTokenExpired::forAccount($this->account->getKey());
                    }

                    throw $e;
                }

                foreach ($response['value'] ?? [] as $event) {
                    $events[] = isset($event['@removed'])
                        ? $this->tombstone((string) ($event['id'] ?? ''))
                        : $this->normalize($event);
                }

                $follow = $response['@odata.nextLink'] ?? null;
                $deltaLink = $response['@odata.deltaLink'] ?? $deltaLink;
            }

            return new CalendarSyncResult(
                events: $events,
                nextSyncToken: is_string($deltaLink) && $deltaLink !== '' ? $deltaLink : null,
            );
        }

        if (is_string($nextLink) && $nextLink !== '') {
            return new CalendarSyncResult(events: $events, nextSyncToken: null, nextPageToken: $nextLink);
        }

        $nextWindow = $this->nextWindowUrl($url);

        if ($nextWindow !== null) {
            return new CalendarSyncResult(events: $events, nextSyncToken: null, nextPageToken: $nextWindow);
        }

        return new CalendarSyncResult(
            events: $events,
            nextSyncToken: is_string($deltaLink) && $deltaLink !== '' ? $deltaLink : null,
        );
    }

    private function historyStart(): Carbon
    {
        return Date::parse('1990-01-01T00:00:00Z');
    }

    private function horizon(): Carbon
    {
        return Date::now()->addYears(self::WINDOW_YEARS);
    }

    private function calendarWindowUrl(Carbon $start): string
    {
        $end = $start->copy()->addYears(self::WINDOW_YEARS);
        $horizon = $this->horizon();

        if ($end->gt($horizon)) {
            $end = $horizon;
        }

        return self::CALENDAR_DELTA
            .'?startDateTime='.rawurlencode($start->toIso8601String())
            .'&endDateTime='.rawurlencode($end->toIso8601String());
    }

    private function nextWindowUrl(string $currentUrl): ?string
    {
        $end = $this->endDateTimeFromUrl($currentUrl);

        if (! $end instanceof Carbon) {
            return null;
        }

        if ($end->gte($this->horizon())) {
            return null;
        }

        return $this->calendarWindowUrl($end);
    }

    private function endDateTimeFromUrl(string $url): ?Carbon
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);

        $end = $params['endDateTime'] ?? null;

        if (! is_string($end) || $end === '') {
            return null;
        }

        return Date::parse($end);
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
