<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

final readonly class CustomFieldsValidationResult
{
    /**
     * @param  array<string, mixed>  $cleanFields
     */
    public function __construct(
        public array $cleanFields,
        public ?string $error,
    ) {}
}
