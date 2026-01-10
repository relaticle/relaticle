<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Relaticle\ImportWizard\Data\ColumnAnalysis;
use Relaticle\ImportWizard\Data\ValueIssue;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Support\CsvAnalyzer;
use Relaticle\ImportWizard\Support\DateValidator;

/** @property Collection<int, ColumnAnalysis> $columnAnalyses */
trait HasValueAnalysis
{
    protected function analyzeColumns(): void
    {
        if ($this->persistedFilePath === null) {
            $this->columnAnalysesData = [];

            return;
        }

        $analyzer = resolve(CsvAnalyzer::class);

        // Get the model class from the importer for custom field lookup
        $entityType = $this->getEntityTypeForAnalysis();

        $analyses = $analyzer->analyze(
            csvPath: $this->persistedFilePath,
            columnMap: $this->columnMap,
            importerColumns: $this->importerColumns,
            entityType: $entityType,
        );

        // Store as serializable array data
        $this->columnAnalysesData = $analyses->map(fn (ColumnAnalysis $analysis): array => $analysis->toArray())->all();
    }

    protected function getEntityTypeForAnalysis(): ?string
    {
        $importerClass = $this->getImporterClass();

        if ($importerClass === null) {
            return null;
        }

        // Get the model class from the importer
        return $importerClass::getModel();
    }

    public function hasValidationErrors(): bool
    {
        return $this->columnAnalyses->contains(
            fn (ColumnAnalysis $analysis): bool => $analysis->hasErrors()
        );
    }

    public function getTotalErrorCount(): int
    {
        return $this->columnAnalyses->sum(
            fn (ColumnAnalysis $analysis): int => $analysis->getErrorCount()
        );
    }

    /** @return Collection<int, ColumnAnalysis> */
    #[Computed]
    public function columnAnalyses(): Collection
    {
        return collect($this->columnAnalysesData)->map(fn (array $data): ColumnAnalysis => ColumnAnalysis::from($data));
    }

    public function correctValue(string $fieldName, string $oldValue, string $newValue): void
    {
        if (! isset($this->valueCorrections[$fieldName])) {
            $this->valueCorrections[$fieldName] = [];
        }

        $this->valueCorrections[$fieldName][$oldValue] = $newValue;

        // Revalidate the corrected value
        $this->revalidateCorrectedValue($fieldName, $oldValue, $newValue);
    }

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
            ->all();

        // Validate the new value (if not blank/skipped)
        if ($newValue !== '') {
            $analyzer = resolve(CsvAnalyzer::class);
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

    public function isValueSkipped(string $fieldName, string $value): bool
    {
        return $this->hasCorrectionForValue($fieldName, $value)
            && $this->getCorrectedValue($fieldName, $value) === '';
    }

    public function removeCorrectionForValue(string $fieldName, string $oldValue): void
    {
        if (isset($this->valueCorrections[$fieldName][$oldValue])) {
            unset($this->valueCorrections[$fieldName][$oldValue]);
        }

        if (blank($this->valueCorrections[$fieldName])) {
            unset($this->valueCorrections[$fieldName]);
        }
    }

    public function getCorrectedValue(string $fieldName, string $originalValue): ?string
    {
        return $this->valueCorrections[$fieldName][$originalValue] ?? null;
    }

    public function hasCorrectionForValue(string $fieldName, string $value): bool
    {
        return isset($this->valueCorrections[$fieldName][$value]);
    }

    public function loadMoreValues(): void
    {
        $this->reviewPage++;
    }

    public function toggleShowOnlyErrors(): void
    {
        $this->showOnlyErrors = ! $this->showOnlyErrors;
        $this->reviewPage = 1;
    }

    public function changeDateFormat(string $fieldName, string $formatValue): void
    {
        $format = DateFormat::tryFrom($formatValue);
        if (! $format instanceof DateFormat) {
            return;
        }

        // Store the selected format
        $this->selectedDateFormats[$fieldName] = $formatValue;

        // Find the column analysis for this field
        $analysisIndex = collect($this->columnAnalysesData)
            ->search(fn (array $data): bool => $data['mappedToField'] === $fieldName);

        if ($analysisIndex === false) {
            return;
        }

        // Get the unique values for this column
        /** @var array<string, int> $uniqueValues */
        $uniqueValues = $this->columnAnalysesData[$analysisIndex]['uniqueValues'];

        // Re-validate with the new format
        $dateValidator = resolve(DateValidator::class);
        $validationResult = $dateValidator->validateColumn($uniqueValues, $format);

        // Add required field issue if needed
        $issues = $validationResult['issues'];
        $blankCount = $this->columnAnalysesData[$analysisIndex]['blankCount'];
        $isRequired = $this->columnAnalysesData[$analysisIndex]['isRequired'] ?? false;

        if ($isRequired && $blankCount > 0) {
            array_unshift($issues, new ValueIssue(
                value: '',
                message: 'This field is required',
                rowCount: $blankCount,
                severity: 'error',
            ));
        }

        // Update the analysis data
        $this->columnAnalysesData[$analysisIndex]['issues'] = collect($issues)
            ->map(fn (ValueIssue $issue): array => $issue->toArray())
            ->values()
            ->all();
        $this->columnAnalysesData[$analysisIndex]['selectedDateFormat'] = $formatValue;
    }

    public function getSelectedDateFormat(string $fieldName): ?string
    {
        return $this->selectedDateFormats[$fieldName] ?? null;
    }
}
