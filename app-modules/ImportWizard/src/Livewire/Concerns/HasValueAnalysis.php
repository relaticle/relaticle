<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Relaticle\ImportWizard\Data\ColumnAnalysis;
use Relaticle\ImportWizard\Services\CsvAnalyzer;

/**
 * Provides value analysis functionality for the Import Wizard's Review step.
 *
 * @property Collection<int, ColumnAnalysis> $columnAnalyses
 */
trait HasValueAnalysis
{
    /**
     * Analyze all mapped columns for unique values and issues.
     */
    protected function analyzeColumns(): void
    {
        if ($this->persistedFilePath === null) {
            $this->columnAnalysesData = [];

            return;
        }

        $analyzer = app(CsvAnalyzer::class);

        $analyses = $analyzer->analyze(
            csvPath: $this->persistedFilePath,
            columnMap: $this->columnMap,
            importerColumns: $this->importerColumns,
        );

        // Store as serializable array data
        $this->columnAnalysesData = $analyses->map(fn (ColumnAnalysis $analysis): array => $analysis->toArray())->toArray();
    }

    /**
     * Get column analyses as DTOs (computed from stored array data).
     *
     * @return Collection<int, ColumnAnalysis>
     */
    #[Computed]
    public function columnAnalyses(): Collection
    {
        return collect($this->columnAnalysesData)->map(fn (array $data): ColumnAnalysis => ColumnAnalysis::from($data));
    }

    /**
     * Apply a value correction.
     */
    public function correctValue(string $fieldName, string $oldValue, string $newValue): void
    {
        if (! isset($this->valueCorrections[$fieldName])) {
            $this->valueCorrections[$fieldName] = [];
        }

        $this->valueCorrections[$fieldName][$oldValue] = $newValue;
    }

    /**
     * Skip a value (mark it to be excluded from import).
     */
    public function skipValue(string $fieldName, string $oldValue): void
    {
        // If already skipped, unskip it
        if ($this->isValueSkipped($fieldName, $oldValue)) {
            $this->removeCorrectionForValue($fieldName, $oldValue);

            return;
        }

        // Skip by setting correction to empty string
        $this->correctValue($fieldName, $oldValue, '');
    }

    /**
     * Check if a value is skipped.
     */
    public function isValueSkipped(string $fieldName, string $value): bool
    {
        return $this->hasCorrectionForValue($fieldName, $value)
            && $this->getCorrectedValue($fieldName, $value) === '';
    }

    /**
     * Remove a value correction.
     */
    public function removeCorrectionForValue(string $fieldName, string $oldValue): void
    {
        if (isset($this->valueCorrections[$fieldName][$oldValue])) {
            unset($this->valueCorrections[$fieldName][$oldValue]);
        }

        if (empty($this->valueCorrections[$fieldName])) {
            unset($this->valueCorrections[$fieldName]);
        }
    }

    /**
     * Get the corrected value for a field, or null if no correction exists.
     */
    public function getCorrectedValue(string $fieldName, string $originalValue): ?string
    {
        return $this->valueCorrections[$fieldName][$originalValue] ?? null;
    }

    /**
     * Check if a value has a correction.
     */
    public function hasCorrectionForValue(string $fieldName, string $value): bool
    {
        return isset($this->valueCorrections[$fieldName][$value]);
    }

    /**
     * Load more values for the current column (infinite scroll).
     */
    public function loadMoreValues(): void
    {
        $this->reviewPage++;
    }
}
