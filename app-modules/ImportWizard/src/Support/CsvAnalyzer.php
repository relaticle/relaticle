<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Models\CustomField;
use Filament\Actions\Imports\ImportColumn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use League\Csv\Statement;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Services\ValidationService;
use Relaticle\ImportWizard\Data\ColumnAnalysis;
use Relaticle\ImportWizard\Data\ValueIssue;
use Spatie\LaravelData\DataCollection;

final readonly class CsvAnalyzer
{
    public function __construct(
        private CsvReaderFactory $csvReaderFactory,
        private ValidationService $validationService,
        private DataTypeInferencer $dataTypeInferencer,
        private DateValidator $dateValidator,
    ) {}

    /**
     * @param  array<string, string>  $columnMap
     * @param  array<ImportColumn>  $importerColumns
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

                if (blank($value)) {
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

            $fieldType = $this->determineFieldType($collector['importerColumn'], $collector['customField']);
            $isRequired = $collector['importerColumn']?->isMappingRequired() ?? false;

            // Initialize date format properties
            $detectedDateFormat = null;
            $dateFormatConfidence = null;

            // For date fields, detect format and use DateValidator
            if ($this->isDateFieldType($fieldType)) {
                $nonBlankValues = array_keys(
                    array_filter($collector['values'], fn (int $count, string $val): bool => $val !== '', ARRAY_FILTER_USE_BOTH)
                );

                if ($nonBlankValues !== []) {
                    $dateFormatResult = $this->dataTypeInferencer->detectDateFormat($nonBlankValues);
                    $detectedDateFormat = $dateFormatResult->detectedFormat;
                    $dateFormatConfidence = $dateFormatResult->confidence;

                    // Use DateValidator for date field validation
                    $dateValidation = $this->dateValidator->validateColumn(
                        $collector['values'],
                        $detectedDateFormat
                    );

                    // Add required field issue if needed
                    $issues = $dateValidation['issues'];
                    if ($isRequired && $collector['blankCount'] > 0) {
                        array_unshift($issues, new ValueIssue(
                            value: '',
                            message: 'This field is required',
                            rowCount: $collector['blankCount'],
                            severity: 'error',
                        ));
                    }

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
                        detectedDateFormat: $detectedDateFormat,
                        selectedDateFormat: null,
                        dateFormatConfidence: $dateFormatConfidence,
                    );
                }
            }

            // Get validation rules for non-date fields
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

    /** @return Collection<int, CustomField> */
    private function loadCustomFieldsForEntity(?string $entityType): Collection
    {
        if ($entityType === null) {
            return collect();
        }

        // Convert model class to morph alias (e.g., App\Models\Company -> company)
        $morphAlias = $this->getMorphAlias($entityType);

        /** @var Collection<int, CustomField> */
        return CustomField::query()
            ->where('entity_type', $morphAlias)
            ->with('options')
            ->get();
    }

    private function getMorphAlias(string $modelClass): string
    {
        if (! class_exists($modelClass)) {
            return $modelClass;
        }

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass;

        return $model->getMorphClass();
    }

    /** @param Collection<int, CustomField> $customFields */
    private function getCustomFieldForColumn(string $fieldName, Collection $customFields): ?CustomField
    {
        if (! str_starts_with($fieldName, 'custom_fields_')) {
            return null;
        }

        $code = str_replace('custom_fields_', '', $fieldName);

        return $customFields->firstWhere('code', $code);
    }

    /** @return array{rules: array<int|string, mixed>, itemRules: array<int|string, mixed>, isMultiValue: bool} */
    private function getValidationRulesForColumn(
        ?ImportColumn $importerColumn,
        ?CustomField $customField,
    ): array {
        $rules = [];
        $itemRules = [];
        $isMultiValue = false;

        // Get rules from ImportColumn
        if ($importerColumn instanceof ImportColumn) {
            $rules = $this->filterValidatableRules($importerColumn->getDataValidationRules());
        }

        // For custom fields, use ValidationService to get complete rules
        if ($customField instanceof CustomField) {
            $customFieldRules = $this->filterValidatableRules(
                $this->validationService->getValidationRules($customField)
            );
            $rules = $this->mergeValidationRules($rules, $customFieldRules);
            $itemRules = $this->validationService->getItemValidationRules($customField);
            $isMultiValue = $customField->isMultiChoiceField();

            // For choice fields, add validation against available options
            if ($customField->isChoiceField()) {
                $optionNames = $customField->options->pluck('name')->map(fn (mixed $name): string => (string) $name)->all();
                if ($optionNames !== []) {
                    $inRule = 'in:'.implode(',', $optionNames);
                    if ($isMultiValue) {
                        $itemRules[] = $inRule;
                    } else {
                        $rules[] = $inRule;
                    }
                }
            }
        }

        return [
            'rules' => $rules,
            'itemRules' => $itemRules,
            'isMultiValue' => $isMultiValue,
        ];
    }

    /**
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
            if (blank($value)) {
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
                if ($issue instanceof ValueIssue) {
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

    /** @param array<int|string, mixed> $itemRules */
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

    private function determineFieldType(?ImportColumn $column, ?CustomField $customField = null): string
    {
        // Check custom field type first (takes priority)
        if ($customField instanceof CustomField) {
            return $customField->typeData->dataType->value;
        }

        // Check ImportColumn validation rules for date hints
        if ($column instanceof ImportColumn) {
            $rules = $column->getDataValidationRules();
            $rulesString = implode('|', array_map(strval(...), $rules));

            if (str_contains($rulesString, 'date') || str_contains($rulesString, 'Date')) {
                return FieldDataType::DATE->value;
            }
        }

        return FieldDataType::STRING->value;
    }

    private function isDateFieldType(string $fieldType): bool
    {
        return in_array($fieldType, [FieldDataType::DATE->value, FieldDataType::DATE_TIME->value], true);
    }

    /** @param array<ImportColumn> $importerColumns */
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
            $morphAlias = $this->getMorphAlias($entityType);
            $customField = CustomField::query()
                ->where('entity_type', $morphAlias)
                ->where('code', $code)
                ->first();
        }

        // Get validation rules
        $rulesData = $this->getValidationRulesForColumn($importerColumn, $customField);

        // Skip validation if blank value
        if (blank($value)) {
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
