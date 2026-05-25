<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Factories;

use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Data\NormalizedAttendee;
use Relaticle\EmailIntegration\Data\NormalizedMeetingPayload;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Enums\CalendarVisibility;

final class NormalizedMeetingPayloadFactory
{
    public function fromCalendarEvent(CalendarEventData $event, string $accountEmail): NormalizedMeetingPayload
    {
        $attendees = [];
        $selfResponse = null;
        $normalizedAccountEmail = strtolower($accountEmail);

        foreach ($event->attendees as $att) {
            $email = $att['email'];
            $isSelf = $email === $normalizedAccountEmail;
            $response = $this->parseResponseStatus($att['response_status']);

            if ($isSelf) {
                $selfResponse = $response;
            }

            $attendees[] = new NormalizedAttendee(
                emailAddress: $email,
                name: $att['name'],
                responseStatus: $response,
                isOrganizer: $att['is_organizer'],
                isSelf: $isSelf,
            );
        }

        $title = $event->title ?? '';

        return new NormalizedMeetingPayload(
            providerEventId: $event->providerEventId,
            providerRecurringEventId: $event->providerRecurringEventId,
            icalUid: $event->iCalUid,
            title: $title !== '' ? $title : '(no title)',
            description: $event->description,
            location: $event->location,
            startsAt: $event->startsAt,
            endsAt: $event->endsAt,
            allDay: $event->isAllDay,
            organizerEmail: $event->organizerEmail,
            organizerName: $event->organizerName,
            status: $this->parseStatus($event->status),
            visibility: $this->parseVisibility($event->visibility),
            selfResponseStatus: $selfResponse,
            htmlLink: $event->htmlLink,
            attendees: $attendees,
        );
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
