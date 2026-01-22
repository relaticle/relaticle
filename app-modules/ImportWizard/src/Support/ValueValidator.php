<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Illuminate\Support\Facades\Validator;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Rules\ImportChoiceRule;
use Relaticle\ImportWizard\Rules\ImportDateRule;

/**
 * Central validation service for import values.
 *
 * Uses ImportField->rules combined with custom import-specific rules
 * (date format parsing, choice field validation) to validate raw CSV values.
 */
final readonly class ValueValidator
{
    /**
     * Validate a value against an ImportField's rules.
     *
     * @param  ImportField  $field  The field definition with validation rules
     * @param  string  $value  The raw value to validate
     * @param  DateFormat|null  $dateFormat  The selected date format for date fields
     * @param  array<int, array{value: string, label: string}>|null  $choiceOptions  Options for choice fields
     * @return string|null Error message if invalid, null if valid
     */
    public function validate(
        ImportField $field,
        string $value,
        ?DateFormat $dateFormat = null,
        ?array $choiceOptions = null,
    ): ?string {
        if ($value === '') {
            return $field->required
                ? (string) __('validation.required', ['attribute' => $field->label])
                : null;
        }

        $rules = $this->buildRules($field, $dateFormat, $choiceOptions);

        if ($rules === []) {
            return null;
        }

        $validator = Validator::make(
            ['value' => $value],
            ['value' => $rules],
            [],
            ['value' => $field->label]
        );

        return $validator->fails()
            ? $validator->errors()->first('value')
            : null;
    }

    /**
     * Build the validation rules array for a field.
     *
     * @param  ImportField  $field  The field definition
     * @param  DateFormat|null  $dateFormat  Date format for date fields
     * @param  array<int, array{value: string, label: string}>|null  $choiceOptions  Options for choice fields
     * @return array<int, mixed> Laravel validation rules
     */
    private function buildRules(
        ImportField $field,
        ?DateFormat $dateFormat,
        ?array $choiceOptions,
    ): array {
        // Start with base rules from ImportField, excluding required/nullable
        $rules = $this->filterRequiredRules($field->rules);

        // Date fields: add custom date parsing rule
        if ($field->type?->isDateOrDateTime() && $dateFormat !== null) {
            $rules[] = new ImportDateRule($dateFormat);
        }

        // Choice fields: add case-insensitive validation rule
        if ($field->type?->isChoiceField() && $choiceOptions !== null) {
            $rules[] = new ImportChoiceRule(
                $choiceOptions,
                $field->type === FieldDataType::MULTI_CHOICE,
            );
        }

        return $rules;
    }

    /**
     * Filter out required/nullable rules since we handle those separately.
     *
     * @param  array<string>  $rules
     * @return array<string>
     */
    private function filterRequiredRules(array $rules): array
    {
        return array_values(array_diff($rules, ['required', 'nullable']));
    }
}
