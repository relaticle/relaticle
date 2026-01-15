<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Data\ColumnAnalysis;
use Relaticle\ImportWizard\Data\ValueIssue;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\TimestampFormat;
use Relaticle\ImportWizard\Support\CsvAnalyzer;
use Relaticle\ImportWizard\Support\DateValidator;
use Relaticle\ImportWizard\Support\TimestampValidator;

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

        // Store uniqueValues and analysis data in cache (NOT in Livewire state)
        // This prevents PayloadTooLargeException when dealing with large datasets
        foreach ($analyses as $analysis) {
            $this->cacheUniqueValues($analysis->csvColumnName, $analysis->uniqueValues);
            $this->cacheAnalysisData($analysis->csvColumnName, $analysis->toArray());
        }

        // Store only minimal metadata in columnAnalysesData to minimize Livewire payload
        // CRITICAL: Remove both uniqueValues AND issues to prevent PayloadTooLargeException
        $this->columnAnalysesData = $analyses->map(function (ColumnAnalysis $analysis): array {
            $data = $analysis->toArray();

            // Don't serialize - stored in cache instead
            unset($data['uniqueValues']);

            // Store issue counts only, not full issues array (which can be huge)
            /** @var array<int, array<string, mixed>> $issues */
            $issues = $data['issues'] ?? [];
            $data['issueCount'] = count($issues);
            $data['errorCount'] = collect($issues)->where('severity', 'error')->count();
            $data['warningCount'] = collect($issues)->where('severity', 'warning')->count();
            unset($data['issues']); // Don't serialize - stored in cache

            return $data;
        })->all();
    }

    /**
     * Store unique values in cache for a column.
     *
     * @param  array<string, int>  $uniqueValues
     */
    protected function cacheUniqueValues(string $csvColumnName, array $uniqueValues): void
    {
        if ($this->sessionId === null) {
            return;
        }

        Cache::put(
            $this->getUniqueValuesCacheKey($csvColumnName),
            $uniqueValues,
            now()->addHours(24)
        );
    }

    /**
     * Store analysis data in cache for a column (for API access).
     *
     * @param  array<string, mixed>  $analysisData
     */
    protected function cacheAnalysisData(string $csvColumnName, array $analysisData): void
    {
        if ($this->sessionId === null) {
            return;
        }

        Cache::put(
            $this->getAnalysisDataCacheKey($csvColumnName),
            $analysisData,
            now()->addHours(24)
        );
    }

    /**
     * Store corrections in cache for a field (for API access).
     *
     * @param  array<string, string>  $corrections
     */
    protected function cacheCorrections(string $fieldName, array $corrections): void
    {
        if ($this->sessionId === null) {
            return;
        }

        Cache::put(
            $this->getCorrectionsCacheKey($fieldName),
            $corrections,
            now()->addHours(24)
        );
    }

    /**
     * Get cache key for analysis data.
     */
    private function getAnalysisDataCacheKey(string $csvColumnName): string
    {
        return "import:{$this->sessionId}:analysis:{$csvColumnName}";
    }

    /**
     * Get cache key for corrections.
     */
    private function getCorrectionsCacheKey(string $fieldName): string
    {
        return "import:{$this->sessionId}:corrections:{$fieldName}";
    }

    /**
     * Get unique values from cache for a column.
     *
     * @return array<string, int>
     */
    public function getUniqueValuesForColumn(string $csvColumnName): array
    {
        if ($this->sessionId === null) {
            return [];
        }

        return Cache::get($this->getUniqueValuesCacheKey($csvColumnName), []);
    }

    /**
     * Get cache key for unique values.
     */
    private function getUniqueValuesCacheKey(string $csvColumnName): string
    {
        return "import:{$this->sessionId}:values:{$csvColumnName}";
    }

    /**
     * Clear all cached unique values for this session.
     */
    protected function clearUniqueValuesCache(): void
    {
        foreach ($this->columnAnalysesData as $data) {
            if (isset($data['csvColumnName'])) {
                Cache::forget($this->getUniqueValuesCacheKey($data['csvColumnName']));
            }
        }
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

    /**
     * Pre-calculated date previews per column.
     *
     * Using #[Computed] ensures this is NOT serialized to payload (avoids PayloadTooLargeException).
     * Memoized within each request for performance.
     *
     * @return array<string, array<string, string|null>>
     */
    #[Computed]
    public function parsedDatePreviews(): array
    {
        $previews = [];

        foreach ($this->selectedDateFormats as $fieldName => $formatValue) {
            $format = DateFormat::tryFrom($formatValue);
            if ($format === null) {
                continue;
            }

            // Find the column analysis for this field to get csvColumnName
            $analysisData = collect($this->columnAnalysesData)
                ->firstWhere('mappedToField', $fieldName);

            if ($analysisData === null) {
                continue;
            }

            // Get unique values from cache (not from serialized state)
            /** @var array<string, int> $uniqueValues */
            $uniqueValues = $this->getUniqueValuesForColumn($analysisData['csvColumnName']);

            $previews[$fieldName] = [];
            foreach ($uniqueValues as $value => $count) {
                if ($value !== '') {
                    $parsed = $format->parse((string) $value);
                    $previews[$fieldName][(string) $value] = $parsed?->format('M j, Y');
                }
            }
        }

        return $previews;
    }

    public function correctValue(string $fieldName, string $oldValue, string $newValue): void
    {
        if (! isset($this->valueCorrections[$fieldName])) {
            $this->valueCorrections[$fieldName] = [];
        }

        $this->valueCorrections[$fieldName][$oldValue] = $newValue;

        // Cache corrections for API access
        $this->cacheCorrections($fieldName, $this->valueCorrections[$fieldName]);

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

        $csvColumnName = $this->columnAnalysesData[$analysisIndex]['csvColumnName'];

        // Get issues from CACHE (not Livewire state - issues are too large)
        /** @var array<string, mixed>|null $cachedAnalysis */
        $cachedAnalysis = Cache::get($this->getAnalysisDataCacheKey($csvColumnName));
        if ($cachedAnalysis === null) {
            return;
        }

        /** @var array<int, array<string, mixed>> $existingIssues */
        $existingIssues = $cachedAnalysis['issues'] ?? [];

        // Remove old issue for this value
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
                $uniqueValues = $this->getUniqueValuesForColumn($csvColumnName);

                $issues[] = [
                    'value' => $oldValue,
                    'message' => $errorMessage,
                    'rowCount' => $uniqueValues[$oldValue] ?? 1,
                    'severity' => 'error',
                ];
            }
        }

        // Update cached analysis data (includes full issues for API access)
        $cachedAnalysis['issues'] = $issues;
        $this->cacheAnalysisData($csvColumnName, $cachedAnalysis);

        // Update ONLY the counts in Livewire state (not full issues)
        $this->columnAnalysesData[$analysisIndex]['issueCount'] = count($issues);
        $this->columnAnalysesData[$analysisIndex]['errorCount'] = collect($issues)->where('severity', 'error')->count();
        $this->columnAnalysesData[$analysisIndex]['warningCount'] = collect($issues)->where('severity', 'warning')->count();
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
            // Clear cached corrections when empty
            if ($this->sessionId !== null) {
                Cache::forget($this->getCorrectionsCacheKey($fieldName));
            }
        } else {
            // Update cached corrections
            $this->cacheCorrections($fieldName, $this->valueCorrections[$fieldName]);
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

    /**
     * Get paginated unique values for display.
     *
     * @return array<string, int>
     */
    public function getPaginatedValues(string $csvColumnName, int $page = 1, int $perPage = 100): array
    {
        $values = $this->getUniqueValuesForColumn($csvColumnName);

        return array_slice($values, 0, $page * $perPage, preserve_keys: true);
    }

    /**
     * Get paginated error values for display.
     *
     * @param  array<string>  $errorValues
     * @return array<string, int>
     */
    public function getPaginatedErrorValues(string $csvColumnName, array $errorValues, int $page = 1, int $perPage = 100): array
    {
        $values = $this->getUniqueValuesForColumn($csvColumnName);

        $filteredValues = array_filter(
            $values,
            fn (int $count, string $value): bool => in_array($value, $errorValues, true),
            ARRAY_FILTER_USE_BOTH
        );

        return array_slice($filteredValues, 0, $page * $perPage, preserve_keys: true);
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
        // Store the selected format
        $this->selectedDateFormats[$fieldName] = $formatValue;

        // Reset pagination to limit DOM updates (prevents browser freeze with large datasets)
        $this->reviewPage = 1;

        // Find the column analysis for this field
        $analysisIndex = collect($this->columnAnalysesData)
            ->search(fn (array $data): bool => $data['mappedToField'] === $fieldName);

        if ($analysisIndex === false) {
            return;
        }

        // Determine if this is a datetime field
        $fieldType = $this->columnAnalysesData[$analysisIndex]['fieldType'] ?? null;
        $isDateTimeField = $fieldType === FieldDataType::DATE_TIME->value;

        // Get the unique values from cache (not serialized state)
        $csvColumnName = $this->columnAnalysesData[$analysisIndex]['csvColumnName'];
        /** @var array<string, int> $uniqueValues */
        $uniqueValues = $this->getUniqueValuesForColumn($csvColumnName);

        // Re-validate with the new format using the appropriate validator
        if ($isDateTimeField) {
            $format = TimestampFormat::tryFrom($formatValue);
            if (! $format instanceof TimestampFormat) {
                return;
            }
            $timestampValidator = resolve(TimestampValidator::class);
            $validationResult = $timestampValidator->validateColumn($uniqueValues, $format);
        } else {
            $format = DateFormat::tryFrom($formatValue);
            if (! $format instanceof DateFormat) {
                return;
            }
            $dateValidator = resolve(DateValidator::class);
            $validationResult = $dateValidator->validateColumn($uniqueValues, $format);
        }

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

        // Convert issues to array format
        $issuesArray = collect($issues)
            ->map(fn (ValueIssue $issue): array => $issue->toArray())
            ->values()
            ->all();

        // Update ONLY counts in Livewire state (not full issues - they're too large)
        $this->columnAnalysesData[$analysisIndex]['selectedDateFormat'] = $formatValue;
        $this->columnAnalysesData[$analysisIndex]['issueCount'] = count($issuesArray);
        $this->columnAnalysesData[$analysisIndex]['errorCount'] = collect($issuesArray)->where('severity', 'error')->count();
        $this->columnAnalysesData[$analysisIndex]['warningCount'] = collect($issuesArray)->where('severity', 'warning')->count();

        // Get cached analysis and update with full issues for API access
        /** @var array<string, mixed>|null $cachedAnalysis */
        $cachedAnalysis = Cache::get($this->getAnalysisDataCacheKey($csvColumnName));
        if ($cachedAnalysis !== null) {
            $cachedAnalysis['issues'] = $issuesArray;
            $cachedAnalysis['selectedDateFormat'] = $formatValue;
            $this->cacheAnalysisData($csvColumnName, $cachedAnalysis);
        }

        // Dispatch browser event so Alpine can reload values
        $this->dispatch('format-changed', field: $fieldName, csvColumn: $csvColumnName, format: $formatValue);
    }

    public function getSelectedDateFormat(string $fieldName): ?string
    {
        return $this->selectedDateFormats[$fieldName] ?? null;
    }

    /**
     * Fetch paginated values on-demand for Alpine.js.
     * This method is called directly by the frontend and does NOT store data in Livewire state.
     *
     * @return array{
     *     values: array<int, array{value: string, count: int, issue: array<string, mixed>|null, isSkipped: bool, correctedValue: string|null, parsedDate: string|null}>,
     *     hasMore: bool,
     *     total: int,
     *     showing: int
     * }
     */
    public function fetchValuesPage(string $csvColumnName, string $fieldName, int $page = 1, int $perPage = 100, bool $errorsOnly = false): array
    {
        $allValues = $this->getUniqueValuesForColumn($csvColumnName);

        // Get analysis data for issues
        $analysisData = collect($this->columnAnalysesData)
            ->firstWhere('csvColumnName', $csvColumnName);

        /** @var array<int, array<string, mixed>> $issuesData */
        $issuesData = $analysisData['issues'] ?? [];
        $issuesByValue = collect($issuesData)->keyBy('value');

        // Filter to errors only if requested
        if ($errorsOnly) {
            $errorValues = collect($issuesData)
                ->where('severity', 'error')
                ->pluck('value')
                ->all();
            $allValues = array_filter(
                $allValues,
                fn (int $count, string $value): bool => in_array($value, $errorValues, true),
                ARRAY_FILTER_USE_BOTH
            );
        }

        $total = count($allValues);
        $paginatedValues = array_slice($allValues, 0, $page * $perPage, preserve_keys: true);

        // Get date format for preview if this is a date field
        $effectiveDateFormat = null;
        if (isset($analysisData['fieldType'])) {
            $isDateField = in_array($analysisData['fieldType'], ['date', 'datetime'], true);
            if ($isDateField) {
                $formatValue = $this->selectedDateFormats[$fieldName] ?? $analysisData['detectedDateFormat'] ?? null;
                $effectiveDateFormat = $formatValue !== null ? DateFormat::tryFrom($formatValue) : null;
            }
        }

        // Build response with all data needed for rendering
        $values = [];
        foreach ($paginatedValues as $value => $count) {
            $stringValue = (string) $value;
            $issue = $issuesByValue->get($stringValue);
            $isSkipped = $this->isValueSkipped($fieldName, $stringValue);
            $correctedValue = $this->getCorrectedValue($fieldName, $stringValue);

            // Calculate parsed date preview
            $parsedDate = null;
            if ($effectiveDateFormat !== null && $stringValue !== '' && $issue === null) {
                $parsed = $effectiveDateFormat->parse($stringValue);
                $parsedDate = $parsed?->format('M j, Y');
            }

            $values[] = [
                'value' => $stringValue,
                'count' => $count,
                'issue' => $issue,
                'isSkipped' => $isSkipped,
                'correctedValue' => $correctedValue,
                'parsedDate' => $parsedDate,
            ];
        }

        return [
            'values' => $values,
            'hasMore' => count($paginatedValues) < $total,
            'total' => $total,
            'showing' => count($paginatedValues),
        ];
    }
}
