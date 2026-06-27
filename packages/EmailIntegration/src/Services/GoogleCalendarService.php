<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Google\Client as GoogleClient;
use Google\Service\Calendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Exception;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Data;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;

final readonly class GoogleCalendarService implements CalendarServiceInterface
{
    private function __construct(
        private ConnectedAccount $account,
        private Calendar $client,
    ) {}

    public static function forAccount(ConnectedAccount $account): self
    {
        $google = new GoogleClient;
        $google->setClientId((string) config('services.gmail.client_id'));
        $google->setClientSecret((string) config('services.gmail.client_secret'));

        // `expires_in` must reflect seconds remaining from `created` (= now). If the stored token
        // has already lapsed we pass 0 so `isAccessTokenExpired()` fires and refresh kicks in.
        $secondsUntilExpiry = $account->token_expires_at !== null && $account->token_expires_at->isFuture()
            ? (int) abs(now()->diffInSeconds($account->token_expires_at))
            : 0;

        $google->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $secondsUntilExpiry,
            'created' => now()->timestamp,
        ]);

        if ($google->isAccessTokenExpired() && $account->refresh_token) {
            $google->fetchAccessTokenWithRefreshToken($account->refresh_token);

            $token = $google->getAccessToken();
            $account->update([
                'access_token' => $token['access_token'] ?? $account->access_token,
                'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
                'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            ]);
        }

        return new self($account, new Calendar($google));
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
            // Google omits the organizer on some events (holidays, birthdays, imported .ics),
            // so getOrganizer() can be null — the DTO types both fields as ?string.
            organizerEmail: $organizer?->getEmail(),
            organizerName: $organizer?->getDisplayName(),
            attendees: $attendees,
        );
    }
}
