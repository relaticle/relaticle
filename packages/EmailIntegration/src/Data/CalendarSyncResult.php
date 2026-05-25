<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

final readonly class CalendarSyncResult
{
    /**
     * @param  array<int, CalendarEventData>  $events
     */
    public function __construct(
        public array $events,
        public ?string $nextSyncToken,
    ) {}
}
