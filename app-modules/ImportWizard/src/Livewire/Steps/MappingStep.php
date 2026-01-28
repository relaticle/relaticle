<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Steps;

use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\ImportFieldCollection;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Importers\BaseImporter;
use Relaticle\ImportWizard\Livewire\Concerns\WithImportStore;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\DataTypeInferencer;

/**
 * Step 2: Column mapping.
 *
 * Maps CSV columns to entity fields with auto-detection and manual adjustment.
 * Uses a unified ColumnData DTO keyed by source (CSV column).
 */
final class MappingStep extends Component
{
    use WithImportStore;

    /**
     * Unified column mappings array.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $columns = [];

    private ?BaseImporter $importer = null;

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->mountWithImportStore($storeId, $entityType);
        $this->loadMappings();

        if ($this->columns === []) {
            $this->autoMap();
        }
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.mapping-step', [
            'headers' => $this->headers(),
            'rowCount' => $this->rowCount(),
        ]);
    }

    // =========================================================================
    // COMPUTED PROPERTIES
    // =========================================================================

    /**
     * Get all importable fields (standard + custom).
     */
    #[Computed]
    public function allFields(): ImportFieldCollection
    {
        return $this->getImporter()->allFields();
    }

    /**
     * Get entity link definitions (relationships and Record custom fields).
     *
     * @return array<string, EntityLink>
     */
    #[Computed]
    public function entityLinks(): array
    {
        return $this->getImporter()->entityLinks();
    }

    /**
     * Get required fields that are not mapped.
     */
    #[Computed]
    public function unmappedRequired(): ImportFieldCollection
    {
        $mappedFieldKeys = $this->mappedFieldKeys();

        return $this->allFields()->filter(
            fn (ImportField $field): bool => $field->required && ! in_array($field->key, $mappedFieldKeys, true)
        );
    }

    /**
     * Check if there are any entity links defined.
     */
    #[Computed]
    public function hasEntityLinks(): bool
    {
        return $this->entityLinks() !== [];
    }

    /**
     * Get field keys that are currently mapped (excludes entity link mappings).
     *
     * @return list<string>
     */
    #[Computed]
    public function mappedFieldKeys(): array
    {
        /** @var list<string> */
        return collect($this->columns)
            ->filter(fn (array $m): bool => ($m['entityLink'] ?? null) === null)
            ->pluck('target')
            ->values()
            ->all();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Check if a CSV column is mapped.
     */
    public function isMapped(string $source): bool
    {
        return isset($this->columns[$source]);
    }

    /**
     * Check if a target (field key) is already mapped to any source.
     */
    public function isTargetMapped(string $target): bool
    {
        return collect($this->columns)
            ->contains(fn (array $m): bool => $m['target'] === $target && ($m['entityLink'] ?? null) === null);
    }

    /**
     * Get the mapping for a CSV column.
     */
    public function getMapping(string $source): ?ColumnData
    {
        if (! isset($this->columns[$source])) {
            return null;
        }

        return ColumnData::from($this->columns[$source]);
    }

    /**
     * Get the CSV column that is mapped to a specific field key.
     */
    public function getSourceForTarget(string $target): ?string
    {
        return collect($this->columns)
            ->filter(fn (array $m): bool => $m['target'] === $target && ($m['entityLink'] ?? null) === null)
            ->keys()
            ->first();
    }

    /**
     * Get the field for a CSV column, or null if not mapped.
     */
    public function getFieldForSource(string $source): ?ImportField
    {
        $mapping = $this->getMapping($source);

        if (! $mapping instanceof ColumnData || $mapping->isEntityLinkMapping()) {
            return null;
        }

        return $this->allFields()->get($mapping->target);
    }

    /**
     * Get entity link mapping for a CSV column, or null.
     *
     * @return array{linkKey: string, link: EntityLink, matcherKey: string}|null
     */
    public function getEntityLinkForSource(string $source): ?array
    {
        $mapping = $this->getMapping($source);

        if (! $mapping instanceof ColumnData || $mapping->isFieldMapping()) {
            return null;
        }

        $link = $this->entityLinks()[$mapping->entityLink] ?? null;

        return $link !== null
            ? ['linkKey' => $mapping->entityLink, 'link' => $link, 'matcherKey' => $mapping->target]
            : null;
    }

    /**
     * Get preview values for a CSV column.
     *
     * @return array<string>
     */
    public function previewValues(string $column, int $limit = 5): array
    {
        $store = $this->store();
        if (! $store instanceof ImportStore) {
            return [];
        }

        return $store
            ->query()
            ->limit($limit)
            ->get()
            ->pluck('raw_data')
            ->map(fn ($data): string => (string) ($data[$column] ?? ''))
            ->all();
    }

    /**
     * Check if user can proceed (all required fields mapped).
     */
    public function canProceed(): bool
    {
        return $this->unmappedRequired()->isEmpty();
    }

    // =========================================================================
    // AUTO-MAPPING
    // =========================================================================

    /**
     * Run auto-mapping for all unmapped columns.
     */
    public function autoMap(): void
    {
        $this->autoMapByHeaders();
        $this->autoMapEntityLinks();
        $this->inferDataTypes();
    }

    /**
     * Auto-map columns based on header name matching.
     */
    private function autoMapByHeaders(): void
    {
        $headers = $this->headers();
        $allFields = $this->allFields();

        foreach ($headers as $header) {
            if ($this->isMapped($header)) {
                continue;
            }

            $field = $allFields->guessFor($header);
            if ($field instanceof ImportField && ! $this->isTargetMapped($field->key)) {
                $this->columns[$header] = ColumnData::toField($header, $field->key)->toArray();
            }
        }
    }

    /**
     * Auto-map entity link columns based on guesses.
     */
    private function autoMapEntityLinks(): void
    {
        $headers = $this->headers();
        $entityLinks = $this->entityLinks();

        foreach ($entityLinks as $linkKey => $entityLink) {
            if ($this->isEntityLinkMapped($linkKey)) {
                continue;
            }

            foreach ($headers as $header) {
                if ($this->isMapped($header)) {
                    continue;
                }

                if ($entityLink->matchesHeader($header)) {
                    $highestMatcher = $entityLink->getHighestPriorityMatcher();
                    if ($highestMatcher !== null) {
                        $this->columns[$header] = ColumnData::toEntityLink(
                            $header,
                            $highestMatcher->field,
                            $linkKey,
                        )->toArray();
                        break;
                    }
                }
            }
        }
    }

    /**
     * Check if an entity link is already mapped.
     */
    public function isEntityLinkMapped(string $linkKey): bool
    {
        return collect($this->columns)
            ->contains(fn (array $m): bool => ($m['entityLink'] ?? null) === $linkKey);
    }

    /**
     * Infer data types for unmapped columns and auto-map high-confidence matches.
     */
    private function inferDataTypes(): void
    {
        $headers = $this->headers();
        $inferencer = new DataTypeInferencer(
            entityName: $this->entityType->value,
            teamId: $this->store()?->teamId(),
        );
        $allFields = $this->allFields();

        foreach ($headers as $header) {
            if ($this->isMapped($header)) {
                continue;
            }

            $values = $this->previewValues($header, 10);
            $result = $inferencer->infer($values);

            if ($result->confidence >= 0.8) {
                $suggestedField = array_find(
                    $result->suggestedFields,
                    fn (string $fieldKey): bool => $allFields->hasKey($fieldKey) && ! $this->isTargetMapped($fieldKey)
                );

                if ($suggestedField !== null) {
                    $this->columns[$header] = ColumnData::toField($header, $suggestedField)->toArray();
                }
            }
        }
    }

    // =========================================================================
    // ACTIONS
    // =========================================================================

    /**
     * Map a CSV column to a field.
     */
    public function mapToField(string $source, string $target): void
    {
        if ($target === '') {
            unset($this->columns[$source]);
        } elseif (! $this->isTargetMapped($target)) {
            $this->columns[$source] = ColumnData::toField($source, $target)->toArray();
        }
    }

    /**
     * Map a CSV column to an entity link.
     */
    public function mapToEntityLink(string $source, string $matcherKey, string $entityLinkKey): void
    {
        $this->columns[$source] = ColumnData::toEntityLink($source, $matcherKey, $entityLinkKey)->toArray();
    }

    /**
     * Clear mapping for a CSV column.
     */
    public function unmapColumn(string $source): void
    {
        unset($this->columns[$source]);
    }

    /**
     * Continue to review step after validating mappings.
     */
    public function continueToReview(): void
    {
        if (! $this->canProceed()) {
            return;
        }

        $this->saveMappings();

        $store = $this->store();
        if ($store instanceof ImportStore) {
            $store->setStatus(ImportStatus::Reviewing);
        }

        $this->dispatch('completed');
    }

    // =========================================================================
    // PERSISTENCE
    // =========================================================================

    /**
     * Save mappings to store.
     */
    private function saveMappings(): void
    {
        $store = $this->store();
        if (! $store instanceof ImportStore) {
            return;
        }

        $mappings = collect($this->columns)
            ->map(fn (array $data): ColumnData => ColumnData::from($data))
            ->values();

        $store->setColumnMappings($mappings);
    }

    /**
     * Load mappings from store.
     */
    private function loadMappings(): void
    {
        $store = $this->store();
        if (! $store instanceof ImportStore) {
            return;
        }

        $stored = $store->columnMappings();

        $this->columns = $stored
            ->keyBy('source')
            ->map(fn (ColumnData $m): array => $m->toArray())
            ->all();
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Get the importer instance.
     */
    private function getImporter(): BaseImporter
    {
        if (! $this->importer instanceof BaseImporter) {
            $store = $this->store();
            $teamId = $store?->teamId() ?? (string) filament()->getTenant()?->getKey();
            $this->importer = $this->entityType->importer($teamId);
        }

        return $this->importer;
    }
}
