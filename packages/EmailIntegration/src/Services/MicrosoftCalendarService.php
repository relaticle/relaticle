<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use JsonException;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Data\CalendarPushChannelData;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Exceptions\CalendarSyncTokenExpired;
use Relaticle\EmailIntegration\Exceptions\MeetingResponseFailed;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Factories\MicrosoftGraphClientFactory;
use Throwable;

final readonly class MicrosoftCalendarService implements CalendarServiceInterface
{
    private const string CALENDAR_DELTA = '/me/calendarView/delta';

    private const int WINDOW_YEARS = 5;

    private const string IMPORT_BOUNDARY_PARAM = 'importBoundaryEndDateTime';

    public function __construct(
        private ConnectedAccount $account,
        private MicrosoftGraphClientFactory $clientFactory,
    ) {}

    public function initialSync(?string $pageToken = null): CalendarSyncResult
    {
        if ($pageToken === null || $pageToken === '') {
            return $this->drainOnePage(
                url: $this->calendarWindowUrl($this->historyStart()),
                isInitial: true,
            );
        }

        $page = $this->decodePageToken($pageToken);

        return $this->drainOnePage(
            url: $page['url'],
            isInitial: true,
            completedCursors: $page['cursors'],
            windowEnd: $page['windowEnd'],
            importBoundary: $page['importBoundary'],
        );
    }

    public function fetchDelta(string $syncToken): CalendarSyncResult
    {
        $events = [];
        $updatedCursors = [];

        foreach ($this->decodeSyncCursors($syncToken) as $cursor) {
            $result = $this->drainOnePage($cursor, isInitial: false);

            foreach ($result->events as $event) {
                $events[] = $event;
            }

            $updatedCursors[] = is_string($result->nextSyncToken) && $result->nextSyncToken !== ''
                ? $result->nextSyncToken
                : $cursor;
        }

        return new CalendarSyncResult(
            events: $events,
            nextSyncToken: $this->encodeSyncCursors($updatedCursors),
        );
    }

    public function respondToEvent(string $eventId, AttendeeResponseStatus $status): void
    {
        $action = match ($status) {
            AttendeeResponseStatus::ACCEPTED => 'accept',
            AttendeeResponseStatus::DECLINED => 'decline',
            AttendeeResponseStatus::TENTATIVE => 'tentativelyAccept',
            AttendeeResponseStatus::NEEDS_ACTION => throw new InvalidArgumentException('Cannot reset an RSVP to needsAction.'),
        };

        try {
            $this->clientFactory->make($this->account)
                ->post('/me/events/'.rawurlencode($eventId).'/'.$action, [
                    'sendResponse' => true,
                ])
                ->throw();
        } catch (Throwable $e) {
            if ($this->organizerCannotRespond($e)) {
                return;
            }

            throw MeetingResponseFailed::fromProvider($e);
        }
    }

    public function findEventIdByICalUid(string $iCalUid, CarbonInterface $occurrenceStartsAt): ?string
    {
        // Microsoft assigns a distinct iCalUId per occurrence, unlike Google.
        $escaped = str_replace("'", "''", $iCalUid);

        try {
            $id = $this->clientFactory->make($this->account)
                ->get('/me/events', [
                    '$filter' => "iCalUId eq '{$escaped}'",
                    '$select' => 'id',
                    '$top' => 1,
                ])
                ->throw()
                ->json('value.0.id');
        } catch (Throwable) {
            return null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function listActiveProviderEventIds(): array
    {
        $ids = [];
        $windowStart = $this->historyStart();
        $importBoundary = $this->importBoundary();

        while ($windowStart->lt($importBoundary)) {
            $url = $this->calendarWindowUrl($windowStart, $importBoundary);

            do {
                $response = $this->clientFactory->make($this->account)
                    ->get($url)
                    ->throw()
                    ->json();

                foreach ($response['value'] ?? [] as $event) {
                    if (isset($event['@removed']) || ($event['isCancelled'] ?? false)) {
                        continue;
                    }

                    $id = $event['id'] ?? null;

                    if (is_string($id) && $id !== '') {
                        $ids[] = $id;
                    }
                }

                $nextLink = $response['@odata.nextLink'] ?? null;

                if (is_string($nextLink) && $nextLink !== '') {
                    $url = $nextLink;

                    continue;
                }

                break;
            } while (true);

            $windowEnd = $windowStart->copy()->addYears(self::WINDOW_YEARS);

            if ($windowEnd->gte($importBoundary)) {
                break;
            }

            $windowStart = $windowEnd;
        }

        return $ids;
    }

    public function ensurePushChannel(string $webhookUrl, string $verificationToken): ?CalendarPushChannelData
    {
        $expiresAt = now()->addDays(2);

        try {
            $response = $this->clientFactory->make($this->account)
                ->post('/subscriptions', [
                    'changeType' => 'created,updated,deleted',
                    'notificationUrl' => $webhookUrl,
                    'resource' => 'me/events',
                    'expirationDateTime' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
                    'clientState' => $verificationToken,
                ])
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        $subscriptionId = (string) ($response['id'] ?? '');

        if ($subscriptionId === '') {
            return null;
        }

        $expiration = $response['expirationDateTime'] ?? null;

        return new CalendarPushChannelData(
            channelId: $subscriptionId,
            resourceId: null,
            verificationToken: $verificationToken,
            expiresAt: is_string($expiration) && $expiration !== ''
                ? Date::parse($expiration)
                : $expiresAt,
        );
    }

    public function stopPushChannel(string $channelId, ?string $resourceId): void
    {
        try {
            $this->clientFactory->make($this->account)
                ->delete('/subscriptions/'.rawurlencode($channelId))
                ->throw();
        } catch (Throwable) {
            // The subscription may already be gone.
        }
    }

    /**
     * @param  list<string>  $completedCursors
     */
    private function drainOnePage(
        string $url,
        bool $isInitial,
        array $completedCursors = [],
        ?CarbonInterface $windowEnd = null,
        ?CarbonInterface $importBoundary = null,
    ): CalendarSyncResult {
        $http = $this->clientFactory->make($this->account);
        $windowEnd ??= $this->endDateTimeFromUrl($url);
        $importBoundary ??= $this->importBoundaryFromUrl($url) ?? $this->importBoundary();

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
            return new CalendarSyncResult(
                events: $events,
                nextSyncToken: null,
                nextPageToken: $this->encodePageToken(
                    $this->withImportBoundary($nextLink, $importBoundary),
                    $completedCursors,
                    $windowEnd,
                    $importBoundary,
                ),
            );
        }

        if (is_string($deltaLink) && $deltaLink !== '') {
            $completedCursors[] = $deltaLink;
        }

        $nextWindow = $this->nextWindowUrlFromEnd($windowEnd, $importBoundary);

        if ($nextWindow !== null) {
            return new CalendarSyncResult(
                events: $events,
                nextSyncToken: null,
                nextPageToken: $this->encodePageToken(
                    $nextWindow,
                    $completedCursors,
                    $this->endDateTimeFromUrl($nextWindow),
                    $importBoundary,
                ),
            );
        }

        return new CalendarSyncResult(
            events: $events,
            nextSyncToken: $this->encodeSyncCursors($completedCursors),
        );
    }

    private function historyStart(): CarbonInterface
    {
        return Date::parse('1990-01-01T00:00:00Z');
    }

    private function importBoundary(): CarbonInterface
    {
        return Date::now()->addYears(self::WINDOW_YEARS);
    }

    private function calendarWindowUrl(CarbonInterface $start, ?CarbonInterface $importBoundary = null): string
    {
        $importBoundary ??= $this->importBoundary();
        $end = $start->copy()->addYears(self::WINDOW_YEARS);

        if ($end->gt($importBoundary)) {
            $end = $importBoundary;
        }

        return self::CALENDAR_DELTA
            .'?startDateTime='.rawurlencode($start->toIso8601String())
            .'&endDateTime='.rawurlencode($end->toIso8601String())
            .'&'.self::IMPORT_BOUNDARY_PARAM.'='.rawurlencode($importBoundary->toIso8601String());
    }

    private function nextWindowUrlFromEnd(?CarbonInterface $end, CarbonInterface $importBoundary): ?string
    {
        if (! $end instanceof CarbonInterface || $end->gte($importBoundary)) {
            return null;
        }

        return $this->calendarWindowUrl($end, $importBoundary);
    }

    /**
     * Graph delta tokens stay bound to the window that created them.
     *
     * @param  list<string>  $cursors
     */
    private function encodePageToken(
        string $url,
        array $cursors,
        ?CarbonInterface $windowEnd,
        CarbonInterface $importBoundary,
    ): string {
        return json_encode([
            'url' => $url,
            'cursors' => $cursors,
            'windowEnd' => $windowEnd?->toIso8601String(),
            'importBoundary' => $importBoundary->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{url: string, cursors: list<string>, windowEnd: CarbonInterface|null, importBoundary: CarbonInterface|null}
     */
    private function decodePageToken(string $pageToken): array
    {
        if (! str_starts_with($pageToken, '{')) {
            return [
                'url' => $pageToken,
                'cursors' => [],
                'windowEnd' => $this->endDateTimeFromUrl($pageToken),
                'importBoundary' => $this->importBoundaryFromUrl($pageToken),
            ];
        }

        try {
            $decoded = json_decode($pageToken, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid Microsoft calendar page token.');
        }

        throw_unless(is_array($decoded), InvalidArgumentException::class, 'Invalid Microsoft calendar page token.');

        $url = $decoded['url'] ?? null;

        throw_if(! is_string($url) || $url === '', InvalidArgumentException::class, 'Invalid Microsoft calendar page token.');

        $rawCursors = $decoded['cursors'] ?? [];

        throw_unless(is_array($rawCursors), InvalidArgumentException::class, 'Invalid Microsoft calendar page token.');

        $cursors = [];

        foreach ($rawCursors as $cursor) {
            if (is_string($cursor) && $cursor !== '') {
                $cursors[] = $cursor;
            }
        }

        $windowEndValue = $decoded['windowEnd'] ?? null;
        $windowEnd = is_string($windowEndValue) && $windowEndValue !== ''
            ? Date::parse($windowEndValue)
            : $this->endDateTimeFromUrl($url);

        $importBoundaryValue = $decoded['importBoundary'] ?? null;
        $importBoundary = is_string($importBoundaryValue) && $importBoundaryValue !== ''
            ? Date::parse($importBoundaryValue)
            : $this->importBoundaryFromUrl($url);

        return [
            'url' => $url,
            'cursors' => $cursors,
            'windowEnd' => $windowEnd,
            'importBoundary' => $importBoundary,
        ];
    }

    /**
     * @param  list<string>  $cursors
     */
    private function encodeSyncCursors(array $cursors): ?string
    {
        $cursors = array_values(array_filter(
            $cursors,
            static fn (string $cursor): bool => $cursor !== '',
        ));

        if ($cursors === []) {
            return null;
        }

        return json_encode($cursors, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function decodeSyncCursors(string $syncToken): array
    {
        try {
            $decoded = json_decode($syncToken, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw CalendarSyncTokenExpired::forAccount($this->account->getKey());
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw CalendarSyncTokenExpired::forAccount($this->account->getKey());
        }

        $cursors = [];

        foreach ($decoded as $cursor) {
            if (is_string($cursor) && $cursor !== '') {
                $cursors[] = $cursor;
            }
        }

        if ($cursors === []) {
            throw CalendarSyncTokenExpired::forAccount($this->account->getKey());
        }

        return $cursors;
    }

    private function importBoundaryFromUrl(string $url): ?CarbonInterface
    {
        $boundary = $this->queryParamFromUrl($url, self::IMPORT_BOUNDARY_PARAM);

        if (! is_string($boundary) || $boundary === '') {
            return null;
        }

        return Date::parse($boundary);
    }

    private function withImportBoundary(string $url, CarbonInterface $importBoundary): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query)) {
            return $url;
        }

        parse_str($query, $params);
        $params[self::IMPORT_BOUNDARY_PARAM] = $importBoundary->toIso8601String();

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        $rebuilt = $path.'?'.http_build_query($params);

        if (is_string($fragment) && $fragment !== '') {
            $rebuilt .= '#'.$fragment;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($scheme) && is_string($host)) {
            $port = parse_url($url, PHP_URL_PORT);
            $rebuilt = $scheme.'://'.$host.(is_int($port) ? ':'.$port : '').$rebuilt;
        }

        return $rebuilt;
    }

    private function endDateTimeFromUrl(string $url): ?CarbonInterface
    {
        $end = $this->queryParamFromUrl($url, 'endDateTime');

        if (! is_string($end) || $end === '') {
            return null;
        }

        return Date::parse($end);
    }

    private function queryParamFromUrl(string $url, string $param): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);

        $value = $params[$param] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
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
            // Graph marks the host as "organizer", not accepted/declined.
            // Leave it empty so a host RSVP chosen in Relaticle is not reset on sync.
            'organizer' => null,
            'none', 'notResponded' => 'needsAction',
            default => null,
        };
    }

    private function organizerCannotRespond(Throwable $e): bool
    {
        if (! $e instanceof RequestException || $e->response->status() !== 400) {
            return false;
        }

        return str_contains(
            strtolower($e->getMessage().' '.$e->response->body()),
            'organizer',
        );
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
