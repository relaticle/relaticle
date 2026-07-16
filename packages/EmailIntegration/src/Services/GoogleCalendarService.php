<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Google\Service\Calendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Exception;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Data;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Factories\GoogleClientFactory;

final readonly class GoogleCalendarService implements CalendarServiceInterface
{
    private function __construct(
        private ConnectedAccount $account,
        private Calendar $client,
    ) {}

    public static function forAccount(ConnectedAccount $account): self
    {
        // Reuse the shared client factory so calendar and mail refresh tokens exactly
        // the same way — including surfacing a revoked/absent grant as an auth error
        // (which flips the account to REAUTH_REQUIRED) instead of persisting a null token.
        return new self($account, new Calendar((new GoogleClientFactory)->make($account)));
    }

    public function account(): ConnectedAccount
    {
        return $this->account;
    }

    public function client(): Calendar
    {
        return $this->client;
    }

    public function initialSync(): Data\CalendarSyncResult
    {
        $events = [];
        $pageToken = null;
        $nextSyncToken = null;

        do {
            $params = [
                'timeMin' => now()->subDays(90)->toRfc3339String(),
                'singleEvents' => true,
                'showDeleted' => false,
                'orderBy' => 'startTime',
                'maxResults' => 250,
            ];

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->client->events->listEvents('primary', $params);

            foreach ($response->getItems() as $event) {
                $events[] = $this->normalizeGoogleEvent($event);
            }

            $pageToken = $response->getNextPageToken();
            $nextSyncToken = $response->getNextSyncToken() ?: $nextSyncToken;
        } while ($pageToken !== null);

        return new Data\CalendarSyncResult(events: $events, nextSyncToken: $nextSyncToken);
    }

    /**
     * @throws Exceptions\CalendarSyncTokenExpired when Google invalidates the syncToken (HTTP 410)
     */
    public function fetchDelta(string $syncToken): Data\CalendarSyncResult
    {
        $events = [];
        $pageToken = null;
        $nextSyncToken = null;

        do {
            $params = [
                'syncToken' => $syncToken,
                'singleEvents' => true,
                'maxResults' => 250,
            ];

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
                unset($params['syncToken']);
            }

            try {
                $response = $this->client->events->listEvents('primary', $params);
            } catch (Exception $e) {
                if ($e->getCode() === 410) {
                    throw Exceptions\CalendarSyncTokenExpired::forAccount($this->account->getKey());
                }
                throw $e;
            }

            foreach ($response->getItems() as $event) {
                $events[] = $this->normalizeGoogleEvent($event);
            }

            $pageToken = $response->getNextPageToken();
            $nextSyncToken = $response->getNextSyncToken() ?: $nextSyncToken;
        } while ($pageToken !== null);

        return new Data\CalendarSyncResult(events: $events, nextSyncToken: $nextSyncToken);
    }

    private function normalizeGoogleEvent(GoogleEvent $event): CalendarEventData
    {
        $start = $event->getStart();
        $end = $event->getEnd();

        $startDate = (string) $start->getDate();
        $endDate = (string) $end->getDate();
        $isAllDay = $startDate !== '';

        // All-day events carry a bare Y-m-d with no zone; parse as UTC so a server in a
        // timezone behind UTC doesn't roll the stored date back a day.
        $startsAt = $isAllDay ? Date::parse($startDate, 'UTC') : Date::parse((string) $start->getDateTime());
        // Google's all-day end.date is EXCLUSIVE (the day after the last day), so a 1-day
        // event spans start..start+1. Subtract a day to store the inclusive last day.
        $endsAt = $isAllDay
            ? Date::parse($endDate, 'UTC')->subDay()
            : Date::parse((string) $end->getDateTime());

        $attendees = [];
        foreach ($event->getAttendees() as $attendee) {
            $attendees[] = [
                'email' => strtolower((string) $attendee->getEmail()),
                'name' => $attendee->getDisplayName(),
                'response_status' => $attendee->getResponseStatus(),
                'is_organizer' => (bool) $attendee->getOrganizer(),
            ];
        }

        $status = (string) $event->getStatus();
        // Google omits the organizer on some events (holidays, birthdays, imported
        // .ics), so getOrganizer() returns null at runtime despite its non-nullable
        // vendor phpdoc (corrected in stubs/Google.stub). The DTO types both as ?string.
        $organizer = $event->getOrganizer();

        return new CalendarEventData(
            providerEventId: (string) $event->getId(),
            providerRecurringEventId: $event->getRecurringEventId(),
            iCalUid: $event->getICalUID(),
            title: $event->getSummary(),
            description: $event->getDescription(),
            startsAt: $startsAt,
            endsAt: $endsAt,
            isAllDay: $isAllDay,
            location: $event->getLocation(),
            htmlLink: $event->getHtmlLink(),
            status: $status !== '' ? $status : 'confirmed',
            visibility: $event->getVisibility(),
            organizerEmail: $organizer?->getEmail(),
            organizerName: $organizer?->getDisplayName(),
            attendees: $attendees,
        );
    }
}
