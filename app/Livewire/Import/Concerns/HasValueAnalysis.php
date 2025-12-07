<?php

declare(strict_types=1);

namespace App\Livewire\Import\Concerns;

use App\Data\Import\ColumnAnalysis;
use App\Services\Import\CsvAnalyzer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

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
     * Get total issue count across all columns.
     */
    public function getTotalIssueCount(): int
    {
        return $this->columnAnalyses->sum(fn (ColumnAnalysis $analysis): int => $analysis->issueCount());
    }

    /**
     * Check if any columns have issues.
     */
    public function hasValidationIssues(): bool
    {
        return $this->getTotalIssueCount() > 0;
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
     * Get all corrections count.
     */
    public function getTotalCorrectionsCount(): int
    {
        $count = 0;
        foreach ($this->valueCorrections as $fieldCorrections) {
            $count += count($fieldCorrections);
        }

        return $count;
    }
}
