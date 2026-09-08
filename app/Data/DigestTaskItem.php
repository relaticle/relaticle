<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class DigestTaskItem
{
    public function __construct(
        public string $title,
        public CarbonImmutable $dueAt,
        public string $editUrl,
    ) {}
}
