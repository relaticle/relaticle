<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use Filament\Actions\Imports\ImportColumn;
use Illuminate\Support\Collection;
use League\Csv\Statement;
use Relaticle\ImportWizard\Data\ColumnAnalysis;
use Relaticle\ImportWizard\Data\ValueIssue;
use Spatie\LaravelData\DataCollection;

/**
 * Analyzes CSV files to extract column statistics, unique values, and detect validation issues.
 *
 * Used in the Review Values step of the import wizard to help users identify
 * and fix data problems before importing.
 */
final readonly class CsvAnalyzer
{
    public function __construct(
        private CsvReaderFactory $csvReaderFactory,
    ) {}

    /**
     * Analyze all mapped columns in a CSV file.
     *
     * @param  array<string, string>  $columnMap  Maps importer field name to CSV column name
     * @param  array<ImportColumn>  $importerColumns  Column definitions from the importer
     * @return Collection<int, ColumnAnalysis>
     */
    public function analyze(
        string $csvPath,
        array $columnMap,
        array $importerColumns,
    ): Collection {
        $csvReader = $this->csvReaderFactory->createFromPath($csvPath);
        $records = (new Statement)->process($csvReader);

        // Convert iterator to array so it can be reused across multiple columns
        $recordsArray = iterator_to_array($records);

        // Build lookup for importer columns by name
        $columnLookup = collect($importerColumns)->keyBy(fn (ImportColumn $col): string => $col->getName());

        return collect($columnMap)
            ->filter(fn (?string $csvColumn): bool => $csvColumn !== null && $csvColumn !== '')
            ->map(function (string $csvColumn, string $fieldName) use ($recordsArray, $columnLookup): ColumnAnalysis {
                /** @var ImportColumn|null $importerColumn */
                $importerColumn = $columnLookup->get($fieldName);

                return $this->analyzeColumn(
                    csvColumnName: $csvColumn,
                    mappedToField: $fieldName,
                    records: $recordsArray,
                    importerColumn: $importerColumn,
                );
            })
            ->values();
    }

    /**
     * Analyze a single column to extract statistics and detect issues.
     *
     * @param  iterable<array<string, mixed>>  $records
     */
    private function analyzeColumn(
        string $csvColumnName,
        string $mappedToField,
        iterable $records,
        ?ImportColumn $importerColumn,
    ): ColumnAnalysis {
        $values = [];
        $blankCount = 0;
        $totalCount = 0;

        foreach ($records as $record) {
            $totalCount++;
            $value = $record[$csvColumnName] ?? '';

            if ($this->isBlank($value)) {
                $blankCount++;
                $values[''] = ($values[''] ?? 0) + 1;
            } else {
                $values[$value] = ($values[$value] ?? 0) + 1;
            }
        }

        // Sort by count descending, then alphabetically
        arsort($values);

        $fieldType = $this->determineFieldType($importerColumn);
        $isRequired = $importerColumn?->isMappingRequired() ?? false;

        $issues = $this->detectIssues(
            values: $values,
            fieldType: $fieldType,
            isRequired: $isRequired,
            blankCount: $blankCount,
        );

        return new ColumnAnalysis(
            csvColumnName: $csvColumnName,
            mappedToField: $mappedToField,
            fieldType: $fieldType,
            totalValues: $totalCount,
            uniqueCount: count($values),
            blankCount: $blankCount,
            uniqueValues: $values,
            issues: new DataCollection(ValueIssue::class, $issues),
            isRequired: $isRequired,
        );
    }

    /**
     * Determine the field type based on ImportColumn configuration.
     */
    private function determineFieldType(?ImportColumn $column): string
    {
        if (! $column instanceof ImportColumn) {
            return 'string';
        }

        if ($column->isBoolean()) {
            return 'boolean';
        }

        if ($column->isNumeric()) {
            return 'numeric';
        }

        // Check validation rules for type hints
        $rules = $column->getDataValidationRules();
        $rulesString = implode('|', array_map(strval(...), $rules));

        if (str_contains($rulesString, 'email')) {
            return 'email';
        }

        if (str_contains($rulesString, 'date') || str_contains($rulesString, 'Date')) {
            return 'date';
        }

        if (str_contains($rulesString, 'url') || str_contains($rulesString, 'URL')) {
            return 'url';
        }

        if (str_contains($rulesString, 'numeric') || str_contains($rulesString, 'integer')) {
            return 'numeric';
        }

        return 'string';
    }

    /**
     * Detect validation issues in the column values.
     *
     * @param  array<string, int>  $values
     * @return array<ValueIssue>
     */
    private function detectIssues(
        array $values,
        string $fieldType,
        bool $isRequired,
        int $blankCount,
    ): array {
        $issues = [];

        // Check for blank values in required field
        if ($isRequired && $blankCount > 0) {
            $issues[] = new ValueIssue(
                value: '',
                message: 'Required field has blank values',
                rowCount: $blankCount,
                severity: 'error',
            );
        }

        // Validate based on field type
        foreach ($values as $value => $count) {
            if ($this->isBlank($value)) {
                continue; // Already handled above
            }

            $issue = $this->validateValue((string) $value, $fieldType, $count);
            if ($issue instanceof ValueIssue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Validate a single value based on field type.
     */
    private function validateValue(string $value, string $fieldType, int $count): ?ValueIssue
    {
        return match ($fieldType) {
            'email' => $this->validateEmail($value, $count),
            'numeric' => $this->validateNumeric($value, $count),
            'boolean' => $this->validateBoolean($value, $count),
            'date' => $this->validateDate($value, $count),
            'url' => $this->validateUrl($value, $count),
            default => null,
        };
    }

    private function validateEmail(string $value, int $count): ?ValueIssue
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return new ValueIssue(
                value: $value,
                message: 'Invalid email format',
                rowCount: $count,
                severity: 'error',
            );
        }

        return null;
    }

    private function validateNumeric(string $value, int $count): ?ValueIssue
    {
        if (! is_numeric($value)) {
            return new ValueIssue(
                value: $value,
                message: 'Expected numeric value',
                rowCount: $count,
                severity: 'error',
            );
        }

        return null;
    }

    private function validateBoolean(string $value, int $count): ?ValueIssue
    {
        $booleanValues = ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off', ''];

        if (! in_array(strtolower($value), $booleanValues, true)) {
            return new ValueIssue(
                value: $value,
                message: 'Expected boolean value (true/false, yes/no, 1/0)',
                rowCount: $count,
                severity: 'error',
            );
        }

        return null;
    }

    private function validateDate(string $value, int $count): ?ValueIssue
    {
        // Try common date formats
        $formats = ['Y-m-d', 'm/d/Y', 'd/m/Y', 'Y/m/d', 'M d, Y', 'F d, Y'];

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $value);
            if ($parsed !== false && $parsed->format($format) === $value) {
                return null;
            }
        }

        // Try strtotime as fallback
        if (strtotime($value) !== false) {
            return null;
        }

        return new ValueIssue(
            value: $value,
            message: 'Unable to parse date format',
            rowCount: $count,
            severity: 'warning',
        );
    }

    private function validateUrl(string $value, int $count): ?ValueIssue
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return new ValueIssue(
                value: $value,
                message: 'Invalid URL format',
                rowCount: $count,
                severity: 'error',
            );
        }

        return null;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
