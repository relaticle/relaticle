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

        // Get the model class from the importer for custom field lookup
        $entityType = $this->getEntityTypeForAnalysis();

        $analyses = $analyzer->analyze(
            csvPath: $this->persistedFilePath,
            columnMap: $this->columnMap,
            importerColumns: $this->importerColumns,
            entityType: $entityType,
        );

        // Store as serializable array data
        $this->columnAnalysesData = $analyses->map(fn (ColumnAnalysis $analysis): array => $analysis->toArray())->toArray();
    }

    /**
     * Get the entity type (model class) for custom field validation lookup.
     */
    protected function getEntityTypeForAnalysis(): ?string
    {
        $importerClass = $this->getImporterClass();

        if ($importerClass === null) {
            return null;
        }

        // Get the model class from the importer
        return $importerClass::getModel();
    }

    /**
     * Check if there are any validation errors in the analyzed columns.
     */
    public function hasValidationErrors(): bool
    {
        return $this->columnAnalyses->contains(
            fn (ColumnAnalysis $analysis): bool => $analysis->hasErrors()
        );
    }

    /**
     * Get the total count of validation errors across all columns.
     */
    public function getTotalErrorCount(): int
    {
        return $this->columnAnalyses->sum(
            fn (ColumnAnalysis $analysis): int => $analysis->getErrorCount()
        );
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
     * Apply a value correction and revalidate.
     */
    public function correctValue(string $fieldName, string $oldValue, string $newValue): void
    {
        if (! isset($this->valueCorrections[$fieldName])) {
            $this->valueCorrections[$fieldName] = [];
        }

        $this->valueCorrections[$fieldName][$oldValue] = $newValue;

        // Revalidate the corrected value
        $this->revalidateCorrectedValue($fieldName, $oldValue, $newValue);
    }

    /**
     * Revalidate a corrected value and update the issues list.
     */
    private function revalidateCorrectedValue(string $fieldName, string $oldValue, string $newValue): void
    {
        // Find the column analysis for this field
        $analysisIndex = collect($this->columnAnalysesData)
            ->search(fn (array $data): bool => $data['mappedToField'] === $fieldName);

        if ($analysisIndex === false) {
            return;
        }

        // Remove old issue for this value
        /** @var array<int, array<string, mixed>> $existingIssues */
        $existingIssues = $this->columnAnalysesData[$analysisIndex]['issues'];
        $issues = collect($existingIssues)
            ->reject(fn (array $issue): bool => $issue['value'] === $oldValue)
            ->values()
            ->toArray();

        // Validate the new value (if not blank/skipped)
        if ($newValue !== '') {
            $analyzer = app(CsvAnalyzer::class);
            $entityType = $this->getEntityTypeForAnalysis();

            $errorMessage = $analyzer->validateSingleValue(
                value: $newValue,
                fieldName: $fieldName,
                importerColumns: $this->importerColumns,
                entityType: $entityType,
            );

            if ($errorMessage !== null) {
                $issues[] = [
                    'value' => $oldValue,
                    'message' => $errorMessage,
                    'rowCount' => $this->columnAnalysesData[$analysisIndex]['uniqueValues'][$oldValue] ?? 1,
                    'severity' => 'error',
                ];
            }
        }

        // Update the analysis data
        $this->columnAnalysesData[$analysisIndex]['issues'] = $issues;
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

    /**
     * Toggle the "show only errors" filter.
     */
    public function toggleShowOnlyErrors(): void
    {
        $this->showOnlyErrors = ! $this->showOnlyErrors;
        $this->reviewPage = 1;
    }
}
