<?php

declare(strict_types=1);

namespace Relaticle\Chat\Data;

use Carbon\CarbonImmutable;

final readonly class MyTaskItem
{
    public function __construct(
        public string $id,
        public string $title,
        public ?CarbonImmutable $dueAt,
        public ?string $severity,
        public string $editUrl,
    ) {}
}
