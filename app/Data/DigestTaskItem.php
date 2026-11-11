<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class DigestTaskItem
{
    /**
     * @param  list<DigestRecord>  $records
     */
    public function __construct(
        public string $title,
        public CarbonImmutable $dueAt,
        public string $editUrl,
        public array $records = [],
        public ?string $priority = null,
        public ?string $priorityColor = null,
    ) {}
}
