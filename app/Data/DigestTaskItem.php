<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Support\Carbon;

final readonly class DigestTaskItem
{
    public function __construct(
        public string $title,
        public Carbon $dueAt,
        public string $editUrl,
    ) {}
}
