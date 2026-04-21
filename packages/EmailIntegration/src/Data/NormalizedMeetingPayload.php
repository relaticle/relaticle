<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Enums\CalendarVisibility;

final readonly class NormalizedMeetingPayload
{
    /**
     * @param  array<int, NormalizedAttendee>  $attendees
     */
    public function __construct(
        public string $providerEventId,
        public ?string $providerRecurringEventId,
        public ?string $icalUid,
        public string $title,
        public ?string $description,
        public ?string $location,
        public Carbon $startsAt,
        public Carbon $endsAt,
        public bool $allDay,
        public ?string $organizerEmail,
        public ?string $organizerName,
        public CalendarEventStatus $status,
        public CalendarVisibility $visibility,
        public ?AttendeeResponseStatus $selfResponseStatus,
        public ?string $htmlLink,
        public array $attendees,
    ) {}
}
