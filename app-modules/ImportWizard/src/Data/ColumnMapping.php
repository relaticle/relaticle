<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Relaticle\ImportWizard\Enums\DateFormat;
use Spatie\LaravelData\Data;

/**
 * Represents a mapping from a CSV column to an entity field or relationship.
 *
 * The naming convention:
 * - source: CSV column header (the key in the columnMappings array)
 * - target: field key for field mappings, or matcherKey for relationship mappings
 * - relationship: if set, indicates this is a relationship mapping
 * - dateFormat: if set, indicates the date format to use for parsing (iso, european, american)
 *
 * The rule: presence of `relationship` determines the mapping type.
 */
final class ColumnMapping extends Data
{
    public function __construct(
        public readonly string $source,
        public readonly string $target,
        public readonly ?string $relationship = null,
        public readonly ?DateFormat $dateFormat = null,
    ) {}

    /**
     * Create a field mapping.
     */
    public static function toField(string $source, string $target): self
    {
        return new self(
            source: $source,
            target: $target,
        );
    }

    /**
     * Create a relationship mapping.
     *
     * @param  string  $source  CSV column header
     * @param  string  $matcherKey  The field to match on (e.g., 'name', 'email')
     * @param  string  $relationship  The relationship name (e.g., 'company')
     */
    public static function toRelationship(string $source, string $matcherKey, string $relationship): self
    {
        return new self(
            source: $source,
            target: $matcherKey,
            relationship: $relationship,
        );
    }

    public function isFieldMapping(): bool
    {
        return $this->relationship === null;
    }

    public function isRelationshipMapping(): bool
    {
        return $this->relationship !== null;
    }

    /**
     * Create a new instance with the given date format.
     */
    public function withDateFormat(?DateFormat $dateFormat): self
    {
        return new self(
            source: $this->source,
            target: $this->target,
            relationship: $this->relationship,
            dateFormat: $dateFormat,
        );
    }
}
