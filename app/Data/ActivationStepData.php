<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One row of the dashboard activation checklist. A step either navigates
 * somewhere (`url`) or seeds the dashboard's chat composer (`prompt`).
 * Exactly one of the two is set.
 */
final readonly class ActivationStepData
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public ?string $url,
        public ?string $prompt,
        public string $icon,
        public bool $complete,
    ) {}
}
