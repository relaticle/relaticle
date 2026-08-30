<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

final readonly class CalendarSyncResult
{
    /**
     * @param  array<int, CalendarEventData>  $events
     * @param  string|null  $nextPageToken  Provider page token / nextLink when more of the initial window remains
     */
    public function __construct(
        public array $events,
        public ?string $nextSyncToken,
        public ?string $nextPageToken = null,
    ) {}
}
