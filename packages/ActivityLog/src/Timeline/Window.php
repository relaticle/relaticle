<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline;

use Carbon\CarbonImmutable;

final readonly class Window
{
    /**
     * @param  array<int, string>|null  $typeAllow
     * @param  array<int, string>|null  $typeDeny
     * @param  array<int, string>|null  $eventAllow
     * @param  array<int, string>|null  $eventDeny
     */
    public function __construct(
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public int $cap = 20,
        public ?array $typeAllow = null,
        public ?array $typeDeny = null,
        public ?array $eventAllow = null,
        public ?array $eventDeny = null,
    ) {}
}
