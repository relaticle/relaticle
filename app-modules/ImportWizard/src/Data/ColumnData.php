<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Livewire\Wireable;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\NumberFormat;
use Spatie\LaravelData\Concerns\WireableData;
use Spatie\LaravelData\Data;

/**
 * Represents a mapping from a CSV column to an entity field or relationship.
 *
 * The naming convention:
 * - source: CSV column header (the key in the ColumnDatas array)
 * - target: field key for field mappings, or matcherKey for relationship mappings
 * - relationship: if set, indicates this is a relationship mapping
 * - dateFormat: if set, indicates the date format to use for parsing (iso, european, american)
 *
 * The rule: presence of `relationship` determines the mapping type.
 *
 * Note: importField and relationshipField are hydrated at runtime by ImportStore, not stored in JSON.
 */
final class ColumnData extends Data implements Wireable
{
    use WireableData;

    /**
     * Hydrated at runtime by ImportStore - not stored in JSON.
     */
    public ?ImportField $importField = null;

    /**
     * Hydrated at runtime by ImportStore - not stored in JSON.
     */
    public ?RelationshipField $relationshipField = null;

    public function __construct(
        public readonly string $source,
        public readonly string $target,
        public readonly ?string $relationship = null,
        public readonly ?DateFormat $dateFormat = null,
        public readonly ?NumberFormat $numberFormat = null,
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
    public static function toRelationship(
        string $source,
        string $matcherKey,
        string $relationship,
    ): self {
        return new self(
            source: $source,
            target: $matcherKey,
            relationship: $relationship,
        );
    }

    /**
     * Get the data type from the hydrated importField.
     */
    public function getType(): FieldDataType
    {
        return $this->importField?->type ?? FieldDataType::STRING;
    }

    /**
     * Get a display label for this mapping.
     */
    public function getLabel(): string
    {
        if ($this->isFieldMapping()) {
            return $this->importField?->label ?? $this->target;
        }

        return $this->relationshipField?->label ?? $this->relationship ?? $this->target;
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
     * Serialize for JSON storage - excludes transient fields (importField, relationshipField).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'target' => $this->target,
            'relationship' => $this->relationship,
            'dateFormat' => $this->dateFormat,
            'numberFormat' => $this->numberFormat,
        ];
    }

    /**
     * Create a new instance with a different date format.
     */
    public function withDateFormat(DateFormat $format): self
    {
        return new self(
            source: $this->source,
            target: $this->target,
            relationship: $this->relationship,
            dateFormat: $format,
            numberFormat: $this->numberFormat,
        );
    }

    /**
     * Create a new instance with a different number format.
     */
    public function withNumberFormat(NumberFormat $format): self
    {
        return new self(
            source: $this->source,
            target: $this->target,
            relationship: $this->relationship,
            dateFormat: $this->dateFormat,
            numberFormat: $format,
        );
    }
}
