<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Google\Service\Calendar\Event;

final readonly class CalendarSyncResult
{
    /**
     * @param  array<int, Event>  $events
     */
    public function __construct(
        public array $events,
        public ?string $nextSyncToken,
    ) {}
}
