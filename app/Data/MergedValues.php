<?php

declare(strict_types=1);

namespace App\Data;

final readonly class MergedValues
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $customFields
     * @param  array<string, array{label: string, limit: int}>  $overLimit
     */
    public function __construct(
        public array $attributes,
        public array $customFields,
        public array $overLimit,
    ) {}
}
