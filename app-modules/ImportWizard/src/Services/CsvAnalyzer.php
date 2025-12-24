<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use App\Models\CustomField;
use Filament\Actions\Imports\ImportColumn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use League\Csv\Statement;
use Relaticle\CustomFields\Services\ValidationService;
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
        private ValidationService $validationService,
    ) {}

    /**
     * Analyze all mapped columns in a CSV file.
     *
     * Uses single-pass analysis to read CSV once and collect stats for all columns simultaneously.
     *
     * @param  array<string, string>  $columnMap  Maps importer field name to CSV column name
     * @param  array<ImportColumn>  $importerColumns  Column definitions from the importer
     * @param  string|null  $entityType  Entity model class for custom field lookup
     * @return Collection<int, ColumnAnalysis>
     */
    public function analyze(
        string $csvPath,
        array $columnMap,
        array $importerColumns,
        ?string $entityType = null,
    ): Collection {
        // Build lookup for importer columns by name
        $columnLookup = collect($importerColumns)->keyBy(fn (ImportColumn $col): string => $col->getName());

        // Pre-load custom fields for this entity type to avoid N+1 queries
        $customFields = $this->loadCustomFieldsForEntity($entityType);

        // Filter out empty column mappings
        $filteredColumnMap = collect($columnMap)
            ->filter(fn (?string $csvColumn): bool => $csvColumn !== null && $csvColumn !== '')
            ->all();

        if ($filteredColumnMap === []) {
            return collect();
        }

        // Initialize collectors for ALL columns before iteration
        /** @var array<string, array{csvColumnName: string, mappedToField: string, values: array<string, int>, blankCount: int, totalCount: int, importerColumn: ImportColumn|null, customField: CustomField|null}> $collectors */
        $collectors = [];
        foreach ($filteredColumnMap as $fieldName => $csvColumn) {
            /** @var ImportColumn|null $importerColumn */
            $importerColumn = $columnLookup->get($fieldName);
            $customField = $this->getCustomFieldForColumn($fieldName, $customFields);

            $collectors[$csvColumn] = [
                'csvColumnName' => $csvColumn,
                'mappedToField' => $fieldName,
                'values' => [],
                'blankCount' => 0,
                'totalCount' => 0,
                'importerColumn' => $importerColumn,
                'customField' => $customField,
            ];
        }

        // SINGLE PASS through CSV - update all collectors simultaneously
        $csvReader = $this->csvReaderFactory->createFromPath($csvPath);
        $records = (new Statement)->process($csvReader);

        foreach ($records as $record) {
            foreach ($collectors as $csvColumn => &$collector) {
                $collector['totalCount']++;
                $value = $record[$csvColumn] ?? '';

                if ($this->isBlank($value)) {
                    $collector['blankCount']++;
                    $collector['values'][''] = ($collector['values'][''] ?? 0) + 1;
                } else {
                    $valueStr = (string) $value;
                    $collector['values'][$valueStr] = ($collector['values'][$valueStr] ?? 0) + 1;
                }
            }
        }

        // Build ColumnAnalysis objects from collected data
        return collect($collectors)->map(function (array $collector): ColumnAnalysis {
            // Sort values by count descending
            arsort($collector['values']);

            $fieldType = $this->determineFieldType($collector['importerColumn']);
            $isRequired = $collector['importerColumn']?->isMappingRequired() ?? false;

            // Get validation rules
            $rulesData = $this->getValidationRulesForColumn(
                $collector['importerColumn'],
                $collector['customField']
            );

            // Detect issues
            $issues = $this->detectIssuesWithValidator(
                values: $collector['values'],
                rulesData: $rulesData,
                fieldName: $collector['mappedToField'],
                isRequired: $isRequired,
            );

            return new ColumnAnalysis(
                csvColumnName: $collector['csvColumnName'],
                mappedToField: $collector['mappedToField'],
                fieldType: $fieldType,
                totalValues: $collector['totalCount'],
                uniqueCount: count($collector['values']),
                blankCount: $collector['blankCount'],
                uniqueValues: $collector['values'],
                issues: new DataCollection(ValueIssue::class, $issues),
                isRequired: $isRequired,
            );
        })->values();
    }

    /**
     * Load all custom fields for an entity type.
     *
     * @return Collection<int, CustomField>
     */
    private function loadCustomFieldsForEntity(?string $entityType): Collection
    {
        if ($entityType === null) {
            return collect();
        }

        return CustomField::query()
            ->where('entity_type', $entityType)
            ->with('options')
            ->get();
    }

    /**
     * Get the custom field for a column if it's a custom field column.
     *
     * @param  Collection<int, CustomField>  $customFields
     */
    private function getCustomFieldForColumn(string $fieldName, Collection $customFields): ?CustomField
    {
        if (! str_starts_with($fieldName, 'custom_fields_')) {
            return null;
        }

        $code = str_replace('custom_fields_', '', $fieldName);

        return $customFields->firstWhere('code', $code);
    }

    /**
     * Get validation rules for a column from ImportColumn and/or CustomField.
     *
     * @return array{rules: array<int|string, mixed>, itemRules: array<int|string, mixed>, isMultiValue: bool}
     */
    private function getValidationRulesForColumn(
        ?ImportColumn $importerColumn,
        ?CustomField $customField,
    ): array {
        $rules = [];
        $itemRules = [];
        $isMultiValue = false;

        // Get rules from ImportColumn
        if ($importerColumn instanceof \Filament\Actions\Imports\ImportColumn) {
            $rules = $this->filterValidatableRules($importerColumn->getDataValidationRules());
        }

        // For custom fields, use ValidationService to get complete rules
        if ($customField instanceof \Relaticle\CustomFields\Models\CustomField) {
            $customFieldRules = $this->validationService->getValidationRules($customField);
            $customFieldRules = $this->filterValidatableRules($customFieldRules);

            // Merge rules, preferring custom field rules for overlapping rule types
            $rules = $this->mergeValidationRules($rules, $customFieldRules);

            // Get item-level validation rules for multi-value fields (e.g., email format)
            $itemRules = $this->validationService->getItemValidationRules($customField);
            $isMultiValue = $customField->isMultiChoiceField();
        }

        return [
            'rules' => $rules,
            'itemRules' => $itemRules,
            'isMultiValue' => $isMultiValue,
        ];
    }

    /**
     * Filter out validation rules that shouldn't be applied to individual values.
     *
     * Some rules like 'array', closure-based rules, or object rules
     * don't make sense for validating individual CSV values.
     *
     * @param  array<int|string, mixed>  $rules
     * @return array<int|string, mixed>
     */
    private function filterValidatableRules(array $rules): array
    {
        return array_filter($rules, function (mixed $rule): bool {
            // Skip closures and objects (like UniqueCustomFieldValue)
            if (! is_string($rule)) {
                return false;
            }

            // Skip array rule (CSV values are strings, not arrays)
            if ($rule === 'array' || str_starts_with($rule, 'array:')) {
                return false;
            }

            // Skip rules that require context we don't have
            $skipRules = ['confirmed', 'same', 'different', 'current_password'];
            $ruleName = explode(':', $rule, 2)[0];

            return ! in_array($ruleName, $skipRules, true);
        });
    }

    /**
     * Merge two sets of validation rules, preferring rules from the second set
     * when rule types overlap.
     *
     * @param  array<int|string, mixed>  $baseRules
     * @param  array<int|string, mixed>  $overrideRules
     * @return array<int|string, mixed>
     */
    private function mergeValidationRules(array $baseRules, array $overrideRules): array
    {
        // Extract rule names from override rules
        $overrideRuleNames = array_map(function (mixed $rule): string {
            if (! is_string($rule)) {
                return '';
            }

            return explode(':', $rule, 2)[0];
        }, $overrideRules);

        // Filter base rules that don't conflict with override rules
        $filteredBaseRules = array_filter($baseRules, function (mixed $rule) use ($overrideRuleNames): bool {
            if (! is_string($rule)) {
                return true;
            }
            $ruleName = explode(':', $rule, 2)[0];

            return ! in_array($ruleName, $overrideRuleNames, true);
        });

        return array_merge(array_values($filteredBaseRules), array_values($overrideRules));
    }

    /**
     * Detect validation issues using Laravel Validator.
     *
     * @param  array<string, int>  $values
     * @param  array{rules: array<int|string, mixed>, itemRules: array<int|string, mixed>, isMultiValue: bool}  $rulesData
     * @return array<ValueIssue>
     */
    private function detectIssuesWithValidator(
        array $values,
        array $rulesData,
        string $fieldName,
        bool $isRequired,
    ): array {
        $issues = [];

        foreach ($values as $value => $count) {
            $value = (string) $value;

            // Skip blank values unless required
            if ($this->isBlank($value)) {
                if ($isRequired) {
                    $issues[] = new ValueIssue(
                        value: $value,
                        message: 'This field is required',
                        rowCount: $count,
                        severity: 'error',
                    );
                }

                continue;
            }

            // Special validation for ID field - must be valid ULID
            if ($fieldName === 'id' && ! Str::isUlid($value)) {
                $issues[] = new ValueIssue(
                    value: $value,
                    message: 'Invalid ID format. Must be a valid ULID (e.g., 01KCCFMZ52QWZSQZWVG0AP704V)',
                    rowCount: $count,
                    severity: 'error',
                );

                continue;
            }

            // For multi-value fields with item rules, split and validate each item
            if ($rulesData['isMultiValue'] && $rulesData['itemRules'] !== []) {
                $issue = $this->validateMultiValueField($value, $rulesData['itemRules'], $count);
                if ($issue instanceof \Relaticle\ImportWizard\Data\ValueIssue) {
                    $issues[] = $issue;
                }

                continue;
            }

            // Skip validation if no rules
            if ($rulesData['rules'] === []) {
                continue;
            }

            // Run Laravel Validator for single-value fields
            $validator = Validator::make(
                [$fieldName => $value],
                [$fieldName => $rulesData['rules']]
            );

            if ($validator->fails()) {
                $message = $validator->errors()->first($fieldName);
                $issues[] = new ValueIssue(
                    value: $value,
                    message: $message,
                    rowCount: $count,
                    severity: 'error',
                );
            }
        }

        return $issues;
    }

    /**
     * Validate a multi-value field by splitting and validating each item.
     *
     * @param  array<int|string, mixed>  $itemRules
     */
    private function validateMultiValueField(string $value, array $itemRules, int $count): ?ValueIssue
    {
        // Split by comma and trim each item
        $items = array_map(trim(...), explode(',', $value));
        $errors = [];

        foreach ($items as $item) {
            // Skip empty items
            if ($item === '') {
                continue;
            }

            $validator = Validator::make(
                ['item' => $item],
                ['item' => $itemRules]
            );

            if ($validator->fails()) {
                $errors[] = $validator->errors()->first('item');
            }
        }

        if ($errors !== []) {
            // Use first unique error message (avoid duplicates for same rule)
            $uniqueErrors = array_unique($errors);

            return new ValueIssue(
                value: $value,
                message: implode('; ', $uniqueErrors),
                rowCount: $count,
                severity: 'error',
            );
        }

        return null;
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

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Validate a single value against the rules for a field.
     * Returns error message if validation fails, null if valid.
     *
     * @param  array<ImportColumn>  $importerColumns
     */
    public function validateSingleValue(
        string $value,
        string $fieldName,
        array $importerColumns,
        ?string $entityType = null,
    ): ?string {
        // Get the importer column
        $columnLookup = collect($importerColumns)->keyBy(fn (ImportColumn $col): string => $col->getName());
        $importerColumn = $columnLookup->get($fieldName);

        // Get custom field if applicable
        $customField = null;
        if ($entityType !== null && str_starts_with($fieldName, 'custom_fields_')) {
            $code = str_replace('custom_fields_', '', $fieldName);
            $customField = CustomField::query()
                ->where('entity_type', $entityType)
                ->where('code', $code)
                ->first();
        }

        // Get validation rules
        $rulesData = $this->getValidationRulesForColumn($importerColumn, $customField);

        // Skip validation if blank value
        if ($this->isBlank($value)) {
            return null;
        }

        // For multi-value fields with item rules, split and validate each item
        if ($rulesData['isMultiValue'] && $rulesData['itemRules'] !== []) {
            $issue = $this->validateMultiValueField($value, $rulesData['itemRules'], 1);

            return $issue?->message;
        }

        // Skip validation if no rules
        if ($rulesData['rules'] === []) {
            return null;
        }

        // Run validator for single-value fields
        $validator = Validator::make(
            [$fieldName => $value],
            [$fieldName => $rulesData['rules']]
        );

        if ($validator->fails()) {
            return $validator->errors()->first($fieldName);
        }

        return null;
    }
}
