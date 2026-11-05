<?php

declare(strict_types=1);

namespace App\Data;

final readonly class AiSettings
{
    /** @param array<string, mixed> $stored */
    public function __construct(private array $stored = []) {}

    public function webSearch(): bool
    {
        return (bool) ($this->stored['web_search'] ?? true);
    }
}
