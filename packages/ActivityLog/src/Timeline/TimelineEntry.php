<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

final readonly class TimelineEntry
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $event,
        public CarbonImmutable $occurredAt,
        public string $dedupKey,
        public int $sourcePriority,
        public ?Model $subject = null,
        public ?Model $causer = null,
        public ?Model $relatedModel = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $icon = null,
        public ?string $color = null,
        public ?string $renderer = null,
        public array $properties = [],
    ) {}
}
