<?php

declare(strict_types=1);

namespace App\Data\Import;

use Spatie\LaravelData\Data;

/**
 * Represents a validation issue found in a CSV column value.
 */
final class ValueIssue extends Data
{
    public function __construct(
        public string $value,
        public string $message,
        public int $rowCount,
        public string $severity = 'error',
    ) {}
}
