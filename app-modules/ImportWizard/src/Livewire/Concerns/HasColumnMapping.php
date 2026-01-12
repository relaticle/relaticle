<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Filament\Actions\Imports\ImportColumn;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Relaticle\ImportWizard\Data\RelationshipField;
use Relaticle\ImportWizard\Filament\Imports\BaseImporter;
use Relaticle\ImportWizard\Support\ColumnMatcher;
use Relaticle\ImportWizard\Support\DataTypeInferencer;

/**
 * @property array<\Filament\Actions\Imports\ImportColumn> $importerColumns
 * @property array<string, RelationshipField> $relationshipFields
 * @property array<string> $hiddenRelationshipColumns
 * @property array<\Filament\Actions\Imports\ImportColumn> $visibleImporterColumns
 *
 * @phpstan-type InferredMapping array{field: string, confidence: float, type: string}
 * @phpstan-type RelationshipMapping array{csvColumn: string, matcher: string}
 */
trait HasColumnMapping
{
    /** @var array<string, array{field: string, confidence: float, type: string}> */
    public array $inferredMappings = [];

    /**
     * Relationship field mappings: relationshipName => [csvColumn, matcher].
     *
     * @var array<string, array{csvColumn: string, matcher: string}>
     */
    public array $relationshipMappings = [];

    /** @return array<ImportColumn> */
    #[Computed]
    public function importerColumns(): array
    {
        $importerClass = $this->getImporterClass();
        if ($importerClass === null) {
            return [];
        }

        return $importerClass::getColumns();
    }

    /**
     * Get relationship fields for two-level selection UI.
     *
     * @return array<string, RelationshipField>
     */
    #[Computed]
    public function relationshipFields(): array
    {
        $importerClass = $this->getImporterClass();
        if ($importerClass === null) {
            return [];
        }

        return $importerClass::getRelationshipFields();
    }

    /**
     * Check if this importer has any relationship fields.
     */
    public function hasRelationshipFields(): bool
    {
        return $this->relationshipFields !== [];
    }

    /**
     * Get fields that should NOT be shown in the regular field dropdown.
     * These are handled by the relationship two-level select instead.
     *
     * @return array<string>
     */
    #[Computed]
    public function hiddenRelationshipColumns(): array
    {
        $hidden = [];

        foreach ($this->relationshipFields as $field) {
            foreach ($field->matchers as $matcher) {
                // Build the internal column name based on relationship + matcher
                $columnName = $this->getInternalColumnName($field->name, $matcher->key);
                $hidden[] = $columnName;
            }
        }

        return $hidden;
    }

    /**
     * Get visible importer columns (excluding relationship-based columns).
     *
     * @return array<ImportColumn>
     */
    #[Computed]
    public function visibleImporterColumns(): array
    {
        $hidden = $this->hiddenRelationshipColumns;

        return collect($this->importerColumns)
            ->reject(fn (ImportColumn $col): bool => in_array($col->getName(), $hidden, true))
            ->values()
            ->all();
    }

    public function mapRelationshipField(string $relationshipName, string $csvColumn, string $matcherKey): void
    {
        $field = $this->relationshipFields[$relationshipName] ?? null;
        if ($field === null) {
            return;
        }

        $this->clearMappingsForCsvColumn($csvColumn);

        // Clear any previous internal column mappings for this relationship
        foreach ($field->matchers as $matcher) {
            $internalName = $this->getInternalColumnName($relationshipName, $matcher->key);
            if (isset($this->columnMap[$internalName])) {
                $this->columnMap[$internalName] = '';
            }
        }

        if ($csvColumn === '') {
            unset($this->relationshipMappings[$relationshipName]);

            return;
        }

        $this->relationshipMappings[$relationshipName] = [
            'csvColumn' => $csvColumn,
            'matcher' => $matcherKey,
        ];

        $internalName = $this->getInternalColumnName($relationshipName, $matcherKey);
        if (isset($this->columnMap[$internalName])) {
            $this->columnMap[$internalName] = $csvColumn;
        }
    }

    /**
     * Update the matcher for a relationship without changing the CSV column.
     */
    public function updateRelationshipMatcher(string $relationshipName, string $matcherKey): void
    {
        if (! isset($this->relationshipMappings[$relationshipName])) {
            return;
        }

        $csvColumn = $this->relationshipMappings[$relationshipName]['csvColumn'];
        $this->mapRelationshipField($relationshipName, $csvColumn, $matcherKey);
    }

    protected function getInternalColumnName(string $relationshipName, string $matcherKey): string
    {
        return "rel_{$relationshipName}_{$matcherKey}";
    }

    private function clearMappingsForCsvColumn(string $csvColumn): void
    {
        foreach ($this->relationshipMappings as $name => $mapping) {
            if ($mapping['csvColumn'] === $csvColumn) {
                unset($this->relationshipMappings[$name]);
            }
        }

        foreach ($this->columnMap as $field => $mappedCsv) {
            if ($mappedCsv === $csvColumn) {
                $this->columnMap[$field] = '';
            }
        }
    }

    /**
     * Check if a CSV column is used by a relationship mapping.
     */
    public function isColumnUsedByRelationship(string $csvColumn): bool
    {
        foreach ($this->relationshipMappings as $mapping) {
            if ($mapping['csvColumn'] === $csvColumn) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the relationship mapping for a CSV column, if any.
     *
     * @return array{relationship: string, matcher: string}|null
     */
    public function getRelationshipForColumn(string $csvColumn): ?array
    {
        foreach ($this->relationshipMappings as $name => $mapping) {
            if ($mapping['csvColumn'] === $csvColumn) {
                return ['relationship' => $name, 'matcher' => $mapping['matcher']];
            }
        }

        return null;
    }

    /**
     * Get relationship info for a field name (like rel_company_name).
     *
     * @return array{field: RelationshipField, matcherKey: string}|null
     */
    public function getRelationshipInfoForField(string $fieldName): ?array
    {
        if (! str_starts_with($fieldName, 'rel_')) {
            return null;
        }

        foreach ($this->relationshipFields as $relName => $field) {
            foreach ($field->matchers as $matcher) {
                $internalName = $this->getInternalColumnName($relName, $matcher->key);
                if ($internalName === $fieldName) {
                    return ['field' => $field, 'matcherKey' => $matcher->key];
                }
            }
        }

        return null;
    }

    protected function autoMapColumns(): void
    {
        if ($this->csvHeaders === [] || $this->importerColumns === []) {
            return;
        }

        $matcher = resolve(ColumnMatcher::class);

        // Initialize columnMap with all columns (hidden ones start empty)
        $this->columnMap = collect($this->importerColumns)
            ->mapWithKeys(fn (ImportColumn $column): array => [$column->getName() => ''])
            ->all();

        // Phase 1: Header matching for VISIBLE ImportColumns only
        // Hidden relationship columns are handled in Phase 2
        foreach ($this->visibleImporterColumns as $column) {
            $match = $matcher->findMatchingHeader($this->csvHeaders, $column->getGuesses());
            if ($match !== null) {
                $this->columnMap[$column->getName()] = $match;
            }
        }

        // Phase 2: Auto-map relationship fields (handles rel_* columns)
        $this->autoMapRelationshipFields($matcher);

        // Phase 3: Data type inference for unmapped CSV columns
        $this->applyDataTypeInference();
    }

    /**
     * Auto-map relationship fields based on CSV header guesses.
     */
    protected function autoMapRelationshipFields(ColumnMatcher $matcher): void
    {
        $this->relationshipMappings = [];
        $usedHeaders = array_filter($this->columnMap);

        foreach ($this->relationshipFields as $relationshipName => $field) {
            // Try each matcher's guesses to find a matching CSV header
            foreach ($field->matchers as $fieldMatcher) {
                $match = $matcher->findMatchingHeader(
                    array_diff($this->csvHeaders, $usedHeaders),
                    $fieldMatcher->guesses
                );

                if ($match !== null) {
                    $this->relationshipMappings[$relationshipName] = [
                        'csvColumn' => $match,
                        'matcher' => $fieldMatcher->key,
                    ];

                    // Also set the internal column map
                    $internalName = $this->getInternalColumnName($relationshipName, $fieldMatcher->key);
                    if (isset($this->columnMap[$internalName])) {
                        $this->columnMap[$internalName] = $match;
                    }

                    $usedHeaders[] = $match;
                    break; // Found a match for this relationship, move to next
                }
            }
        }
    }

    /**
     * Apply data type inference for CSV columns that weren't matched by headers.
     */
    protected function applyDataTypeInference(): void
    {
        $inferencer = resolve(DataTypeInferencer::class);
        $this->inferredMappings = [];

        /** @var array<string> */
        $mappedHeaders = array_filter($this->columnMap);
        /** @var array<string> */
        $unmappedHeaders = array_diff($this->csvHeaders, $mappedHeaders);

        foreach ($unmappedHeaders as $header) {
            /** @var array<mixed> */
            $sampleValues = $this->getColumnPreviewValues($header, 20);
            /** @var array{type: string|null, confidence: float, suggestedFields: array<string>} */
            $inference = $inferencer->inferType($sampleValues);

            if ($inference['type'] === null) {
                continue;
            }

            // Find first unmapped field that matches suggested fields
            // Try both original name and custom_fields_ prefixed version
            $matchedField = collect($inference['suggestedFields'])
                ->flatMap(fn (string $field): array => [$field, "custom_fields_{$field}"])
                ->first(fn (string $fieldName): bool => isset($this->columnMap[$fieldName]) && $this->columnMap[$fieldName] === ''
                );

            if ($matchedField !== null) {
                $this->columnMap[$matchedField] = $header;
                $this->inferredMappings[$header] = [
                    'field' => $matchedField,
                    'confidence' => $inference['confidence'],
                    'type' => $inference['type'],
                ];
            }
        }
    }

    /** @return class-string<BaseImporter>|null */
    protected function getImporterClass(): ?string
    {
        $entities = $this->getEntities();

        return $entities[$this->entityType]['importer'] ?? null;
    }

    public function hasAllRequiredMappings(): bool
    {
        return collect($this->importerColumns)
            ->filter(fn (ImportColumn $column): bool => $column->isMappingRequired())
            ->every(fn (ImportColumn $column): bool => ($this->columnMap[$column->getName()] ?? '') !== '');
    }

    public function mapCsvColumnToField(string $csvColumn, string $fieldName): void
    {
        $this->clearMappingsForCsvColumn($csvColumn);

        if ($fieldName !== '') {
            $this->columnMap[$fieldName] = $csvColumn;
        }
    }

    public function unmapColumn(string $fieldName): void
    {
        if (isset($this->columnMap[$fieldName])) {
            $this->columnMap[$fieldName] = '';
        }
    }

    public function getFieldLabel(string $fieldName): string
    {
        /** @var ImportColumn|null */
        $column = collect($this->importerColumns)
            ->first(fn (ImportColumn $column): bool => $column->getName() === $fieldName);

        return $column?->getLabel() ?? Str::title(str_replace('_', ' ', $fieldName));
    }

    protected function hasUniqueIdentifierMapped(): bool
    {
        $importerClass = $this->getImporterClass();

        if ($importerClass === null) {
            return true; // No importer means skip check
        }

        // Skip check if importer doesn't want the warning
        if ($importerClass::skipUniqueIdentifierWarning()) {
            return true; // Return true to skip warning
        }

        /** @var array<string> */
        $uniqueColumns = $importerClass::getUniqueIdentifierColumns();

        foreach ($uniqueColumns as $column) {
            if (isset($this->columnMap[$column]) && $this->columnMap[$column] !== '') {
                return true;
            }
        }

        return false;
    }

    protected function getMissingUniqueIdentifiersMessage(): string
    {
        $importerClass = $this->getImporterClass();

        if ($importerClass === null) {
            return 'Map a Record ID column';
        }

        return $importerClass::getMissingUniqueIdentifiersMessage();
    }

    protected function hasMappingWarnings(): bool
    {
        if (! $this->hasUniqueIdentifierMapped()) {
            return true;
        }

        return (bool) $this->hasRelationshipCreatingNewRecords();
    }

    protected function hasRelationshipCreatingNewRecords(): bool
    {
        return $this->getRelationshipsCreatingNewRecords() !== [];
    }

    /**
     * @return array<array{relationship: string, matcher: string, label: string}>
     */
    protected function getRelationshipsCreatingNewRecords(): array
    {
        $creating = [];

        foreach ($this->relationshipMappings as $relationshipName => $mapping) {
            $field = $this->relationshipFields[$relationshipName] ?? null;
            $matcher = $field?->getMatcher($mapping['matcher']);

            if ($matcher?->createsNew === true) {
                $creating[] = [
                    'relationship' => $relationshipName,
                    'matcher' => $mapping['matcher'],
                    'label' => $field->label,
                ];
            }
        }

        return $creating;
    }

    protected function getMappingWarningsHtml(): string
    {
        /** @var array<string> */
        $warnings = [];

        if (! $this->hasUniqueIdentifierMapped()) {
            $warnings[] = '<strong>'.$this->getMissingUniqueIdentifiersMessage().'</strong>';
        }

        $creatingRelationships = $this->getRelationshipsCreatingNewRecords();
        foreach ($creatingRelationships as $rel) {
            $warnings[] = "<strong>{$rel['label']}</strong> will create new records if not found by name.";
        }

        if ($warnings === []) {
            return '';
        }

        $docsUrl = route('documentation.show', 'import').'#unique-identifiers';

        return 'Please review the following before continuing:<br><br>'.
            implode('<br><br>', $warnings).'<br><br>'.
            '<a href="'.$docsUrl.'" target="_blank" class="text-primary-600 hover:underline">Learn more about imports</a>';
    }
}
