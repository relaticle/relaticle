<?php

declare(strict_types=1);

namespace App\Data;

final readonly class MergedValues
{
    /**
     * @param  array<string, mixed>  $attributes  native columns
     * @param  array<string, mixed>  $customFields  keyed by field code
     * @param  array<string, int>  $overLimit  field code to the field's limit
     */
    public function __construct(
        public array $attributes,
        public array $customFields,
        public array $overLimit,
    ) {}
}
