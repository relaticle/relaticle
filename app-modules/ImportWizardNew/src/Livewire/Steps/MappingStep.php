<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Livewire\Steps;

use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Relaticle\ImportWizardNew\Data\ColumnMapping;
use Relaticle\ImportWizardNew\Data\ImportField;
use Relaticle\ImportWizardNew\Data\RelationshipField;
use Relaticle\ImportWizardNew\Enums\ImportEntityType;
use Relaticle\ImportWizardNew\Enums\ImportStatus;
use Relaticle\ImportWizardNew\Data\ImportFieldCollection;
use Relaticle\ImportWizardNew\Importers\BaseImporter;
use Relaticle\ImportWizardNew\Livewire\Concerns\WithImportStore;
use Relaticle\ImportWizardNew\Support\DataTypeInferencer;

/**
 * Step 2: Column mapping.
 *
 * Maps CSV columns to entity fields with auto-detection and manual adjustment.
 * Uses a unified ColumnMapping DTO keyed by source (CSV column).
 */
final class MappingStep extends Component
{
    use WithImportStore;

    /**
     * Unified column mappings array.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $columnMappings = [];

    private ?BaseImporter $importer = null;

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->mountWithImportStore($storeId, $entityType);
        $this->loadMappings();

        if ($this->columnMappings === []) {
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
     * Get relationship definitions.
     *
     * @return array<string, RelationshipField>
     */
    #[Computed]
    public function relationships(): array
    {
        return $this->getImporter()->relationships();
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
     * Check if there are any relationship fields defined.
     */
    #[Computed]
    public function hasRelationships(): bool
    {
        return $this->relationships() !== [];
    }

    /**
     * Get field keys that are currently mapped (excludes relationship mappings).
     *
     * @return list<string>
     */
    #[Computed]
    public function mappedFieldKeys(): array
    {
        /** @var list<string> */
        return collect($this->columnMappings)
            ->filter(fn (array $m): bool => $m['relationship'] === null)
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
        return isset($this->columnMappings[$source]);
    }

    /**
     * Check if a target (field key) is already mapped to any source.
     */
    public function isTargetMapped(string $target): bool
    {
        return collect($this->columnMappings)
            ->contains(fn (array $m): bool => $m['target'] === $target && $m['relationship'] === null);
    }

    /**
     * Get the mapping for a CSV column.
     */
    public function getMapping(string $source): ?ColumnMapping
    {
        if (! isset($this->columnMappings[$source])) {
            return null;
        }

        return ColumnMapping::from($this->columnMappings[$source]);
    }

    /**
     * Get the CSV column that is mapped to a specific field key.
     */
    public function getSourceForTarget(string $target): ?string
    {
        return collect($this->columnMappings)
            ->filter(fn (array $m): bool => $m['target'] === $target && $m['relationship'] === null)
            ->keys()
            ->first();
    }

    /**
     * Get the field for a CSV column, or null if not mapped.
     */
    public function getFieldForSource(string $source): ?ImportField
    {
        $mapping = $this->getMapping($source);

        if (! $mapping instanceof \Relaticle\ImportWizardNew\Data\ColumnMapping || $mapping->isRelationshipMapping()) {
            return null;
        }

        return $this->allFields()->get($mapping->target);
    }

    /**
     * Get relationship mapping for a CSV column, or null.
     *
     * @return array{relName: string, field: RelationshipField, matcherKey: string}|null
     */
    public function getRelationshipForSource(string $source): ?array
    {
        $mapping = $this->getMapping($source);

        if (! $mapping instanceof \Relaticle\ImportWizardNew\Data\ColumnMapping || $mapping->isFieldMapping()) {
            return null;
        }

        $field = $this->relationships()[$mapping->relationship] ?? null;

        return $field !== null
            ? ['relName' => $mapping->relationship, 'field' => $field, 'matcherKey' => $mapping->target]
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
        if (! $store instanceof \Relaticle\ImportWizardNew\Store\ImportStore) {
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
        $this->autoMapRelationships();
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
                $this->columnMappings[$header] = ColumnMapping::toField($header, $field->key)->toArray();
            }
        }
    }

    /**
     * Auto-map relationship columns based on guesses.
     */
    private function autoMapRelationships(): void
    {
        $headers = $this->headers();
        $relationships = $this->relationships();

        foreach ($relationships as $relName => $relationship) {
            if ($this->isRelationshipMapped($relName)) {
                continue;
            }

            foreach ($headers as $header) {
                if ($this->isMapped($header)) {
                    continue;
                }

                if ($relationship->matchesHeader($header)) {
                    $highestMatcher = $relationship->getHighestPriorityMatcher();
                    if ($highestMatcher !== null) {
                        $this->columnMappings[$header] = ColumnMapping::toRelationship(
                            $header,
                            $highestMatcher->field,
                            $relName
                        )->toArray();
                        break;
                    }
                }
            }
        }
    }

    /**
     * Check if a relationship is already mapped.
     */
    private function isRelationshipMapped(string $relName): bool
    {
        return collect($this->columnMappings)
            ->contains(fn (array $m): bool => $m['relationship'] === $relName);
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
                    $this->columnMappings[$header] = ColumnMapping::toField($header, $suggestedField)->toArray();
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
            unset($this->columnMappings[$source]);
        } elseif (! $this->isTargetMapped($target)) {
            $this->columnMappings[$source] = ColumnMapping::toField($source, $target)->toArray();
        }
    }

    /**
     * Map a CSV column to a relationship.
     */
    public function mapToRelationship(string $source, string $matcherKey, string $relationship): void
    {
        $this->columnMappings[$source] = ColumnMapping::toRelationship($source, $matcherKey, $relationship)->toArray();
    }

    /**
     * Clear mapping for a CSV column.
     */
    public function unmapColumn(string $source): void
    {
        unset($this->columnMappings[$source]);
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
        if ($store instanceof \Relaticle\ImportWizardNew\Store\ImportStore) {
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
        if (! $store instanceof \Relaticle\ImportWizardNew\Store\ImportStore) {
            return;
        }

        $mappings = collect($this->columnMappings)
            ->map(fn (array $data): ColumnMapping => ColumnMapping::from($data))
            ->values();

        $store->setMappings($mappings);
    }

    /**
     * Load mappings from store.
     */
    private function loadMappings(): void
    {
        $store = $this->store();
        if (! $store instanceof \Relaticle\ImportWizardNew\Store\ImportStore) {
            return;
        }

        $stored = $store->mappings();

        $this->columnMappings = $stored
            ->keyBy('source')
            ->map(fn (ColumnMapping $m): array => $m->toArray())
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
