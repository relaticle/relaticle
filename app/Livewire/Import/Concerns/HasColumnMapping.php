<?php

declare(strict_types=1);

namespace App\Livewire\Import\Concerns;

use App\Filament\Imports\BaseImporter;
use Filament\Actions\Imports\ImportColumn;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

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
        $columns = $this->importerColumns;

        if ($this->csvHeaders === [] || $columns === []) {
            return;
        }

        $lowercaseCsvHeaders = array_map(Str::lower(...), $this->csvHeaders);
        $csvHeadersLookup = array_combine($lowercaseCsvHeaders, $this->csvHeaders);

        $this->columnMap = [];

        foreach ($columns as $column) {
            /** @var ImportColumn $column */
            $columnName = $column->getName();
            $guesses = array_map(Str::lower(...), $column->getGuesses());

            // Find the first matching guess
            $match = Arr::first(
                array_intersect($lowercaseCsvHeaders, $guesses),
            );

            if ($match !== null && isset($csvHeadersLookup[$match])) {
                $this->columnMap[$columnName] = $csvHeadersLookup[$match];
            } else {
                $this->columnMap[$columnName] = '';
            }
        }
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
        $columns = $this->importerColumns;

        foreach ($columns as $column) {
            /** @var ImportColumn $column */
            if ($column->isMappingRequired()) {
                $mappedValue = $this->columnMap[$column->getName()] ?? '';
                if ($mappedValue === '') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get missing required column names.
     *
     * @return array<string>
     */
    public function getMissingRequiredMappings(): array
    {
        $missing = [];
        $columns = $this->importerColumns;

        foreach ($columns as $column) {
            /** @var ImportColumn $column */
            if ($column->isMappingRequired()) {
                $mappedValue = $this->columnMap[$column->getName()] ?? '';
                if ($mappedValue === '') {
                    $missing[] = $column->getLabel();
                }
            }
        }

        return $missing;
    }
}
