<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ActivationStepData
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $url,
        public string $icon,
        public bool $complete,
    ) {}
}
