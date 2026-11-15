<?php

declare(strict_types=1);

namespace App\Queries;

final readonly class ViewerZone
{
    private function __construct(
        public string $name,
        public bool $requiresOffset,
    ) {}

    public static function strict(string $zone): self
    {
        return new self($zone, true);
    }

    public static function lenient(string $zone): self
    {
        return new self($zone, false);
    }
}
