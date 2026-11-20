<?php

declare(strict_types=1);

namespace App\Data;

final readonly class MergedValues
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $customFields
     * @param  array<string, string>  $overLimit
     * @param  array<string, int>  $raises
     * @param  list<string>  $attachmentUuids
     */
    public function __construct(
        public array $attributes,
        public array $customFields,
        public array $overLimit,
        public array $raises,
        public array $attachmentUuids,
    ) {}
}
