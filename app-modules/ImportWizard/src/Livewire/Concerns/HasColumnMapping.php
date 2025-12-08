<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Filament\Actions\Imports\ImportColumn;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Relaticle\ImportWizard\Filament\Imports\BaseImporter;

/**
 * Provides column mapping functionality for the Import Wizard.
 *
 * @property array<\Filament\Actions\Imports\ImportColumn> $importerColumns
 */
trait HasColumnMapping
{
    /**
     * Get importer columns for the selected entity type.
     * This is a computed property to avoid storing complex objects in Livewire state.
     *
     * @return array<ImportColumn>
     */
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
     * Auto-map CSV columns to importer columns based on guesses.
     */
    protected function autoMapColumns(): void
    {
        if ($this->csvHeaders === [] || $this->importerColumns === []) {
            return;
        }

        $csvHeadersLower = collect($this->csvHeaders)->mapWithKeys(
            fn (string $header): array => [Str::lower($header) => $header]
        );

        $this->columnMap = collect($this->importerColumns)
            ->mapWithKeys(function (ImportColumn $column) use ($csvHeadersLower): array {
                $guesses = collect($column->getGuesses())->map(fn (string $g): string => Str::lower($g));
                $match = $guesses->first(fn (string $guess): bool => $csvHeadersLower->has($guess));

                return [$column->getName() => $match !== null ? $csvHeadersLower->get($match) : ''];
            })
            ->toArray();
    }

    /**
     * Get the importer class for the selected entity type.
     *
     * @return class-string<BaseImporter>|null
     */
    protected function getImporterClass(): ?string
    {
        $entities = $this->getEntities();

        return $entities[$this->entityType]['importer'] ?? null;
    }

    /**
     * Check if all required columns are mapped.
     */
    public function hasAllRequiredMappings(): bool
    {
        return collect($this->importerColumns)
            ->filter(fn (ImportColumn $column): bool => $column->isMappingRequired())
            ->every(fn (ImportColumn $column): bool => ($this->columnMap[$column->getName()] ?? '') !== '');
    }

    /**
     * Map a CSV column to a field, or unmap if fieldName is empty.
     */
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

    /**
     * Unmap a column.
     */
    public function unmapColumn(string $fieldName): void
    {
        if (isset($this->columnMap[$fieldName])) {
            $this->columnMap[$fieldName] = '';
        }
    }

    /**
     * Get the label for a field name.
     */
    public function getFieldLabel(string $fieldName): string
    {
        $column = collect($this->importerColumns)
            ->first(fn (ImportColumn $column): bool => $column->getName() === $fieldName);

        return $column?->getLabel() ?? Str::title(str_replace('_', ' ', $fieldName));
    }
}
