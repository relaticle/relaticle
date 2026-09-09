<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Google\Service\Calendar;
use Google\Service\Calendar\Channel;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Exception;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Relaticle\EmailIntegration\Data;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Exceptions\CalendarSyncTokenExpired;
use Relaticle\EmailIntegration\Exceptions\MeetingResponseFailed;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Factories\GoogleClientFactory;
use Throwable;

final readonly class GoogleCalendarService implements CalendarServiceInterface
{
    public function __construct(
        private ConnectedAccount $account,
        private Calendar $client,
    ) {}

    public static function forAccount(ConnectedAccount $account): self
    {
        // Reuse the shared client factory so calendar and mail refresh tokens exactly
        // the same way, including surfacing a revoked/absent grant as an auth error
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

    public function initialSync(?string $pageToken = null): Data\CalendarSyncResult
    {
        $params = [
            'singleEvents' => true,
            'showDeleted' => false,
            'maxResults' => 250,
        ];

        if ($pageToken !== null && $pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }

        $response = $this->client->events->listEvents('primary', $params);

        $events = [];

        foreach ($response->getItems() as $event) {
            $events[] = $this->normalizeGoogleEvent($event);
        }

        $nextPageToken = $response->getNextPageToken();
        $nextSyncToken = $response->getNextSyncToken() ?: null;

        return new Data\CalendarSyncResult(
            events: $events,
            nextSyncToken: $nextSyncToken,
            nextPageToken: ($nextPageToken !== null && $nextPageToken !== '') ? $nextPageToken : null,
        );
    }

    /**
     * @throws CalendarSyncTokenExpired when Google invalidates the syncToken (HTTP 410)
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
                'showDeleted' => true,
                'maxResults' => 250,
            ];

            if ($pageToken !== null) {
                // Google requires the original incremental query, including syncToken, on every page.
                $params['pageToken'] = $pageToken;
            }

            try {
                $response = $this->client->events->listEvents('primary', $params);
            } catch (Exception $e) {
                if ($e->getCode() === 410) {
                    throw CalendarSyncTokenExpired::forAccount($this->account->getKey());
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

    public function listActiveProviderEventIds(): array
    {
        $ids = [];
        $pageToken = null;

        do {
            $params = [
                'singleEvents' => true,
                'showDeleted' => false,
                'maxResults' => 250,
            ];

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->client->events->listEvents('primary', $params);

            foreach ($response->getItems() as $event) {
                if ($event->getStatus() === 'cancelled') {
                    continue;
                }

                $ids[] = (string) $event->getId();
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken !== null);

        return $ids;
    }

    public function respondToEvent(string $eventId, AttendeeResponseStatus $status): void
    {
        $this->assertRespondable($status);

        try {
            $event = $this->client->events->get('primary', $eventId);
            $attendee = $this->selfAttendeeFrom($event);
            $patch = new GoogleEvent;
            $sendUpdates = 'all';

            if ($attendee instanceof EventAttendee) {
                $attendee->setResponseStatus($status->value);
                $patch->setAttendees([$attendee]);
                $patch->setAttendeesOmitted(true);
            } elseif ($this->accountIsOrganizer($event)) {
                $attendee = $this->newSelfAttendee($event->getOrganizer()?->getEmail());
                $attendee->setOrganizer(true);
                $attendee->setResponseStatus($status->value);
                $patch->setAttendees([...$this->attendeesFrom($event), $attendee]);
                $sendUpdates = 'none';
            } else {
                $attendee = $this->newSelfAttendee();
                $attendee->setResponseStatus($status->value);
                $patch->setAttendees([$attendee]);
                $patch->setAttendeesOmitted(true);
            }

            $this->client->events->patch('primary', $eventId, $patch, [
                'sendUpdates' => $sendUpdates,
            ]);
        } catch (Throwable $e) {
            throw MeetingResponseFailed::fromProvider($e);
        }
    }

    public function findEventIdByICalUid(string $iCalUid): ?string
    {
        try {
            $page = $this->client->events->listEvents('primary', [
                'iCalUID' => $iCalUid,
                'maxResults' => 1,
                'showDeleted' => false,
            ]);
        } catch (Throwable) {
            return null;
        }

        $items = $page->getItems();

        if (! is_array($items) || $items === []) {
            return null;
        }

        $id = $items[0]->getId();

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function ensurePushChannel(string $webhookUrl, string $verificationToken): ?Data\CalendarPushChannelData
    {
        $channel = new Channel;
        $channel->setId(Str::uuid()->toString());
        $channel->setType('web_hook');
        $channel->setAddress($webhookUrl);
        $channel->setToken($verificationToken);

        try {
            $response = $this->client->events->watch('primary', $channel);
        } catch (Throwable) {
            return null;
        }

        $expiration = $response->getExpiration();
        $expiresAt = $expiration !== null
            ? Date::createFromTimestampMs((int) $expiration)
            : now()->addDays(6);

        return new Data\CalendarPushChannelData(
            channelId: (string) $response->getId(),
            resourceId: $response->getResourceId(),
            verificationToken: $verificationToken,
            expiresAt: $expiresAt,
        );
    }

    public function stopPushChannel(string $channelId, ?string $resourceId): void
    {
        if ($resourceId === null || $resourceId === '') {
            return;
        }

        try {
            $channel = new Channel;
            $channel->setId($channelId);
            $channel->setResourceId($resourceId);

            $this->client->channels->stop($channel);
        } catch (Throwable) {
            // The channel may already be expired or stopped.
        }
    }

    private function selfAttendeeFrom(GoogleEvent $event): ?EventAttendee
    {
        $attendees = $this->attendeesFrom($event);
        $emailMatch = null;
        $accountEmail = strtolower($this->account->email_address);

        foreach ($attendees as $attendee) {
            if ($attendee->getSelf()) {
                return $attendee;
            }

            if ($emailMatch === null && strtolower((string) $attendee->getEmail()) === $accountEmail) {
                $emailMatch = $attendee;
            }
        }

        return $emailMatch;
    }

    /**
     * @return list<EventAttendee>
     */
    private function attendeesFrom(GoogleEvent $event): array
    {
        return array_values($event->getAttendees() ?: []);
    }

    private function accountIsOrganizer(GoogleEvent $event): bool
    {
        $email = $event->getOrganizer()?->getEmail();

        return is_string($email)
            && $email !== ''
            && strtolower($email) === strtolower($this->account->email_address);
    }

    private function newSelfAttendee(?string $email = null): EventAttendee
    {
        $attendee = new EventAttendee;
        $attendee->setEmail($email ?? $this->account->email_address);

        return $attendee;
    }

    private function assertRespondable(AttendeeResponseStatus $status): void
    {
        throw_if($status === AttendeeResponseStatus::NEEDS_ACTION, InvalidArgumentException::class, 'Cannot reset an RSVP to needsAction.');
    }

    private function normalizeGoogleEvent(GoogleEvent $event): CalendarEventData
    {
        if ($event->getStatus() === 'cancelled') {
            return $this->tombstone($event);
        }

        $start = $event->getStart();
        $end = $event->getEnd();

        $startDate = (string) $start->getDate();
        $endDate = (string) $end->getDate();
        $isAllDay = $startDate !== '';

        // All-day events carry a bare Y-m-d with no zone; parse as UTC so a server in a
        // timezone behind UTC doesn't roll the stored date back a day.
        // Timed events include an offset in dateTime; convert to UTC before persistence
        // because meetings.starts_at is timestamp without time zone.
        $startsAt = $isAllDay
            ? Date::parse($startDate, 'UTC')
            : Date::parse((string) $start->getDateTime())->utc();
        // Google's all-day end.date is EXCLUSIVE (the day after the last day), so a 1-day
        // event spans start..start+1. Subtract a day to store the inclusive last day.
        $endsAt = $isAllDay
            ? Date::parse($endDate, 'UTC')->subDay()
            : Date::parse((string) $end->getDateTime())->utc();

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

    private function tombstone(GoogleEvent $event): CalendarEventData
    {
        $now = Date::now();

        return new CalendarEventData(
            providerEventId: (string) $event->getId(),
            providerRecurringEventId: $event->getRecurringEventId(),
            iCalUid: $event->getICalUID(),
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
}
