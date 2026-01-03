<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Filament\Actions\Imports\ImportColumn;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Relaticle\ImportWizard\Filament\Imports\BaseImporter;
use Relaticle\ImportWizard\Services\ColumnMatcher;
use Relaticle\ImportWizard\Services\DataTypeInferencer;

/**
 * @property array<\Filament\Actions\Imports\ImportColumn> $importerColumns
 *
 * @phpstan-type InferredMapping array{field: string, confidence: float, type: string}
 */
trait HasColumnMapping
{
    /** @var array<string, array{field: string, confidence: float, type: string}> */
    public array $inferredMappings = [];

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

    protected function autoMapColumns(): void
    {
        if ($this->csvHeaders === [] || $this->importerColumns === []) {
            return;
        }

        $matcher = app(ColumnMatcher::class);

        // Phase 1: Header matching
        $this->columnMap = collect($this->importerColumns)
            ->mapWithKeys(function (ImportColumn $column) use ($matcher): array {
                $match = $matcher->findMatchingHeader($this->csvHeaders, $column->getGuesses());

                return [$column->getName() => $match ?? ''];
            })
            ->toArray();

        // Phase 2: Data type inference for unmapped CSV columns
        $this->applyDataTypeInference();
    }

    /**
     * Apply data type inference for CSV columns that weren't matched by headers.
     */
    protected function applyDataTypeInference(): void
    {
        $inferencer = app(DataTypeInferencer::class);
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
            foreach ($inference['suggestedFields'] as $suggestedField) {
                if (isset($this->columnMap[$suggestedField]) && $this->columnMap[$suggestedField] === '') {
                    // Auto-apply the inference
                    $this->columnMap[$suggestedField] = $header;
                    $this->inferredMappings[$header] = [
                        'field' => $suggestedField,
                        'confidence' => $inference['confidence'],
                        'type' => $inference['type'],
                    ];

                    break;
                }
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
        // First, find and clear any existing mapping for this CSV column
        foreach ($this->columnMap as $field => $mappedCsv) {
            if ($mappedCsv === $csvColumn) {
                $this->columnMap[$field] = '';
            }
        }

        // If a field was selected, map it
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

        return (bool) $this->hasCompanyNameWithoutId();
    }

    protected function hasCompanyNameWithoutId(): bool
    {
        /** @var \Illuminate\Support\Collection<string, ImportColumn> */
        $columns = collect($this->importerColumns)->keyBy(fn (ImportColumn $col): string => $col->getName());

        if (! $columns->has('company_name') || ! $columns->has('company_id')) {
            return false;
        }

        /** @var bool */
        $hasCompanyName = isset($this->columnMap['company_name']) && $this->columnMap['company_name'] !== '';
        /** @var bool */
        $hasCompanyId = isset($this->columnMap['company_id']) && $this->columnMap['company_id'] !== '';

        return $hasCompanyName && ! $hasCompanyId;
    }

    protected function getMappingWarningsHtml(): string
    {
        /** @var array<string> */
        $warnings = [];

        if (! $this->hasUniqueIdentifierMapped()) {
            $warnings[] = '<strong>'.$this->getMissingUniqueIdentifiersMessage().'</strong>';
        }

        if ($this->hasCompanyNameWithoutId()) {
            $warnings[] = '<strong>Company Name</strong> is mapped without <strong>Company Record ID</strong>. '.
                'New companies will be created for each unique name in your CSV.';
        }

        $docsUrl = route('documentation.show', 'import').'#unique-identifiers';

        return 'Please review the following before continuing:<br><br>'.
            implode('<br><br>', $warnings).'<br><br>'.
            '<a href="'.$docsUrl.'" target="_blank" class="text-primary-600 hover:underline">Learn more about imports</a>';
    }
}
