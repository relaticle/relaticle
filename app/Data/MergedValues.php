<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\CustomField;

final readonly class MergedValues
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $customFields
     * @param  list<array{field: CustomField, value: mixed}>  $inactiveCustomFields
     * @param  array<string, string>  $overLimit
     * @param  array<string, int>  $raises
     * @param  list<string>  $attachmentUuids
     */
    public function __construct(
        public array $attributes,
        public array $customFields,
        public array $inactiveCustomFields,
        public array $overLimit,
        public array $raises,
        public array $attachmentUuids,
    ) {}
}
