<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Illuminate\Support\Carbon;

final readonly class CalendarEventData
{
    /**
     * @param  array<int, array{email: string, name: string|null, response_status: string|null, is_organizer: bool}>  $attendees
     */
    public function __construct(
        public string $providerEventId,
        public ?string $providerRecurringEventId,
        public ?string $iCalUid,
        public ?string $title,
        public ?string $description,
        public Carbon $startsAt,
        public Carbon $endsAt,
        public bool $isAllDay,
        public ?string $location,
        public ?string $htmlLink,
        public string $status,
        public ?string $visibility,
        public ?string $organizerEmail,
        public ?string $organizerName,
        public array $attendees,
    ) {}
}
