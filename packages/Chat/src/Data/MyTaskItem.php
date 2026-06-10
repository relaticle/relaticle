<?php

declare(strict_types=1);

namespace Relaticle\Chat\Data;

use Illuminate\Support\Carbon;

final readonly class MyTaskItem
{
    public function __construct(
        public string $id,
        public string $title,
        public ?Carbon $dueAt,
        public ?string $severity,
        public string $editUrl,
    ) {}
}
