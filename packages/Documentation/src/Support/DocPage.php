<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Carbon\CarbonImmutable;

final readonly class DocPage
{
    /** @param list<string> $related */
    public function __construct(
        public string $path,
        public string $area,
        public string $category,
        public string $slug,
        public string $title,
        public string $description,
        public int $order,
        public array $related,
        public string $body,
        public ?CarbonImmutable $updated,
    ) {}
}
