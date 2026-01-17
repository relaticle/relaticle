<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Data;

use Spatie\LaravelData\Data;

/**
 * Defines a field that can be used to match CSV rows to existing records.
 *
 * Priority determines which field is used when multiple matchable fields are mapped.
 * Higher priority = checked first.
 *
 * If updateOnly is true, rows without a match will be skipped (not created).
 */
final class MatchableField extends Data
{
    public function __construct(
        public readonly string $field,
        public readonly string $label,
        public readonly int $priority = 0,
        public readonly bool $updateOnly = false,
    ) {}

    /**
     * Record ID - highest priority, update only (skip if not found).
     */
    public static function id(): self
    {
        return new self(
            field: 'id',
            label: 'Record ID',
            priority: 100,
            updateOnly: true,
        );
    }

    /**
     * Email field - high priority, creates if not found.
     */
    public static function email(string $fieldKey = 'custom_fields_emails'): self
    {
        return new self(
            field: $fieldKey,
            label: 'Email',
            priority: 90,
        );
    }

    /**
     * Domain field - medium-high priority, creates if not found.
     */
    public static function domain(string $fieldKey = 'custom_fields_domains'): self
    {
        return new self(
            field: $fieldKey,
            label: 'Domain',
            priority: 80,
        );
    }

    /**
     * Phone field - medium priority, creates if not found.
     */
    public static function phone(string $fieldKey = 'custom_fields_phone_number'): self
    {
        return new self(
            field: $fieldKey,
            label: 'Phone',
            priority: 70,
        );
    }

    /**
     * Name field - low priority, for relationship matching only.
     */
    public static function name(): self
    {
        return new self(
            field: 'name',
            label: 'Name',
            priority: 10,
        );
    }
}
