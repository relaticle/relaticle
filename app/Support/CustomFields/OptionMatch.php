<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

final readonly class OptionMatch
{
    public function __construct(
        public string $key,
        public string $label,
    ) {}
}
