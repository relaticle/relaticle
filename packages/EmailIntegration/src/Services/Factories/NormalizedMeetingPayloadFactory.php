<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Factories;

use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\EventOrganizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Data\NormalizedAttendee;
use Relaticle\EmailIntegration\Data\NormalizedMeetingPayload;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Enums\CalendarVisibility;

final class NormalizedMeetingPayloadFactory
{
    public function fromGoogleEvent(Event $event, string $accountEmail): NormalizedMeetingPayload
    {
        /** @var EventDateTime|null $start */
        $start = $event->getStart();
        /** @var EventDateTime|null $end */
        $end = $event->getEnd();

        $allDay = $start !== null && $start->getDate() !== '';

        $startsAt = $this->parseDateTime($start, $allDay);
        $endsAt = $this->parseDateTime($end, $allDay, $startsAt);

        $attendees = [];
        $selfResponse = null;

        /** @var array<int, EventAttendee>|null $rawAttendees */
        $rawAttendees = $event->getAttendees();

        foreach ($rawAttendees ?? [] as $att) {
            $email = strtolower((string) $att->getEmail());
            $isSelf = $email === strtolower($accountEmail);
            $response = $this->parseResponseStatus($att->getResponseStatus());

            if ($isSelf) {
                $selfResponse = $response;
            }

            $attendees[] = new NormalizedAttendee(
                emailAddress: $email,
                name: $att->getDisplayName(),
                responseStatus: $response,
                isOrganizer: (bool) $att->getOrganizer(),
                isSelf: $isSelf,
            );
        }

        /** @var EventOrganizer|null $organizer */
        $organizer = $event->getOrganizer();

        return new NormalizedMeetingPayload(
            providerEventId: (string) $event->getId(),
            providerRecurringEventId: $event->getRecurringEventId(),
            icalUid: $event->getICalUID(),
            title: $event->getSummary() !== '' ? $event->getSummary() : '(no title)',
            description: $event->getDescription(),
            location: $event->getLocation(),
            startsAt: $startsAt,
            endsAt: $endsAt,
            allDay: $allDay,
            organizerEmail: $organizer?->getEmail(),
            organizerName: $organizer?->getDisplayName(),
            status: $this->parseStatus($event->getStatus()),
            visibility: $this->parseVisibility($event->getVisibility()),
            selfResponseStatus: $selfResponse,
            htmlLink: $event->getHtmlLink(),
            attendees: $attendees,
        );
    }

    private function parseDateTime(?EventDateTime $dt, bool $allDay, ?Carbon $fallback = null): Carbon
    {
        if (! $dt instanceof EventDateTime) {
            return $fallback ?? Date::now();
        }

        if ($allDay) {
            $dateStr = $dt->getDate();

            return $dateStr !== '' ? Date::parse($dateStr) : ($fallback ?? Date::now());
        }

        $dateTimeStr = $dt->getDateTime();

        return $dateTimeStr !== '' ? Date::parse($dateTimeStr) : ($fallback ?? Date::now());
    }

    private function parseStatus(?string $value): CalendarEventStatus
    {
        return CalendarEventStatus::tryFrom((string) $value) ?? CalendarEventStatus::CONFIRMED;
    }

    private function parseVisibility(?string $value): CalendarVisibility
    {
        return CalendarVisibility::tryFrom((string) $value) ?? CalendarVisibility::DEFAULT;
    }

    private function parseResponseStatus(?string $value): ?AttendeeResponseStatus
    {
        return $value === null ? null : AttendeeResponseStatus::tryFrom($value);
    }
}
