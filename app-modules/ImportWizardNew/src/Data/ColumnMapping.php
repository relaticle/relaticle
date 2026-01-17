<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Data;

use Spatie\LaravelData\Data;

/**
 * Represents a mapping from a CSV column to an entity field or relationship.
 *
 * Stored in ImportStore meta.json as part of column_mappings.
 */
final class ColumnMapping extends Data
{
    public function __construct(
        public readonly string $column,
        public readonly ?string $field = null,
        public readonly ?string $relationship = null,
        public readonly ?string $relationshipMatch = null,
        public readonly ?string $format = null,
    ) {}

    /**
     * Create a field mapping.
     */
    public static function field(string $column, string $field, ?string $format = null): self
    {
        return new self(
            column: $column,
            field: $field,
            format: $format,
        );
    }

    /**
     * Create a relationship mapping.
     */
    public static function relationship(string $column, string $relationship, string $relationshipMatch): self
    {
        return new self(
            column: $column,
            relationship: $relationship,
            relationshipMatch: $relationshipMatch,
        );
    }

    /**
     * Create a skipped column (not mapped).
     */
    public static function skip(string $column): self
    {
        return new self(column: $column);
    }

    public function isRelationship(): bool
    {
        return $this->relationship !== null;
    }

    public function isSkipped(): bool
    {
        return $this->field === null && $this->relationship === null;
    }

    public function isField(): bool
    {
        return $this->field !== null && $this->relationship === null;
    }
}
