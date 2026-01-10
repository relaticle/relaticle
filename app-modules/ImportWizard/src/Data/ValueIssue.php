<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Spatie\LaravelData\Data;

/**
 * Represents a validation issue found in a CSV column value.
 *
 * @property-read string|null $issueType Date issue type: 'invalid', 'ambiguous', or 'format_mismatch'
 */
final class ValueIssue extends Data
{
    public function __construct(
        public readonly string $value,
        public readonly string $message,
        public readonly int $rowCount,
        public readonly string $severity = 'error',
        public readonly ?string $issueType = null,
    ) {}

    /**
     * Check if this is a date-related ambiguity warning.
     */
    public function isDateAmbiguous(): bool
    {
        return $this->issueType === 'ambiguous';
    }
}
