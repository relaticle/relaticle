<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Livewire\Wireable;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\NumberFormat;
use Relaticle\ImportWizard\Importers\BaseImporter;
use Spatie\LaravelData\Concerns\WireableData;
use Spatie\LaravelData\Data;

/**
 * Represents a mapping from a CSV column to an entity field or entity link.
 *
 * The naming convention:
 * - source: CSV column header (the key in the ColumnDatas array)
 * - target: field key for field mappings, or matcherKey for entity link mappings
 * - entityLink: if set, indicates this is an entity link mapping (relationship or Record custom field)
 * - dateFormat: if set, indicates the date format to use for parsing (iso, european, american)
 *
 * The rule: presence of `entityLink` determines the mapping type.
 *
 * Note: importField and entityLinkField are hydrated at runtime by ImportStore, not stored in JSON.
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
    public ?EntityLink $entityLinkField = null;

    public function __construct(
        public readonly string $source,
        public readonly string $target,
        public readonly ?string $entityLink = null,
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
     * Create an entity link mapping (relationship or Record custom field).
     *
     * @param  string  $source  CSV column header
     * @param  string  $matcherKey  The field to match on (e.g., 'name', 'email', 'id')
     * @param  string  $entityLinkKey  The entity link key (e.g., 'company', 'custom_fields_linked_company')
     */
    public static function toEntityLink(
        string $source,
        string $matcherKey,
        string $entityLinkKey,
    ): self {
        return new self(
            source: $source,
            target: $matcherKey,
            entityLink: $entityLinkKey,
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

        return $this->entityLinkField?->label ?? $this->entityLink ?? $this->target;
    }

    /**
     * Get the entity link key if this is an entity link mapping.
     */
    public function getEntityLinkKey(): ?string
    {
        return $this->entityLink;
    }

    public function isFieldMapping(): bool
    {
        return $this->entityLink === null;
    }

    public function isEntityLinkMapping(): bool
    {
        return $this->entityLink !== null;
    }

    /**
     * Get the EntityLink and MatchableField for this column.
     *
     * Uses hydrated entityLinkField if available, otherwise looks up from importer.
     *
     * @return array{link: EntityLink, matcher: MatchableField}|null
     */
    public function resolveEntityLinkContext(BaseImporter $importer): ?array
    {
        if (! $this->isEntityLinkMapping()) {
            return null;
        }

        $link = $this->entityLinkField
            ?? ($importer->entityLinks()[$this->getEntityLinkKey()] ?? null);

        if ($link === null) {
            return null;
        }

        $matcher = $link->getMatcher($this->target);

        return $matcher !== null ? ['link' => $link, 'matcher' => $matcher] : null;
    }

    /**
     * Serialize for JSON storage - excludes transient fields (importField, entityLinkField).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'target' => $this->target,
            'entityLink' => $this->entityLink,
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
            entityLink: $this->entityLink,
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
            entityLink: $this->entityLink,
            dateFormat: $this->dateFormat,
            numberFormat: $format,
        );
    }
}
