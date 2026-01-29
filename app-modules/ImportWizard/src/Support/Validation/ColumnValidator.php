<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support\Validation;

use Illuminate\Support\Facades\Validator;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\NumberFormat;

/**
 * Validates column values based on column configuration.
 *
 * Handles dates, numbers, choices, multi-value fields, and text validation
 * using a match expression to route to the appropriate validation logic.
 */
final class ColumnValidator
{
    public function validate(ColumnData $column, string $value): ?ValidationError
    {
        return match (true) {
            $column->getType()->isDateOrDateTime() => $this->validateDate($column, $value),
            $column->getType()->isFloat() => $this->validateNumber($column, $value),
            $column->isRealChoiceField() => $this->validateChoice($column, $value),
            $column->isMultiValueArbitrary() => $this->validateMultiValue($column, $value),
            default => $this->validateText($column, $value),
        };
    }

    private function validateDate(ColumnData $column, string $value): ?ValidationError
    {
        $format = $column->dateFormat ?? DateFormat::ISO;
        $parsed = $format->parse($value, $column->getType()->isTimestamp());

        return $parsed === null
            ? ValidationError::message("Invalid date format. Expected: {$format->getLabel()}")
            : null;
    }

    private function validateNumber(ColumnData $column, string $value): ?ValidationError
    {
        $format = $column->numberFormat ?? NumberFormat::POINT;
        $parsed = $format->parse($value);

        return $parsed === null
            ? ValidationError::message("Invalid number format. Expected: {$format->getLabel()}")
            : null;
    }

    private function validateChoice(ColumnData $column, string $value): ?ValidationError
    {
        $options = $column->importField->options ?? [];
        $validValues = array_column($options, 'value');

        // For multi-choice fields, validate each value separately
        if ($column->getType()->isMultiChoiceField()) {
            $values = array_filter(array_map('trim', explode(',', $value)));
            $errors = [];

            foreach ($values as $item) {
                if (! in_array($item, $validValues, true)) {
                    $errors[$item] = 'Not a valid option';
                }
            }

            return ! empty($errors) ? ValidationError::itemErrors($errors) : null;
        }

        // Single choice field
        if (in_array($value, $validValues, true)) {
            return null;
        }

        $optionsList = implode(', ', array_slice($validValues, 0, 5));
        $suffix = count($validValues) > 5 ? '...' : '';

        return ValidationError::message("Invalid choice. Must be one of: {$optionsList}{$suffix}");
    }

    private function validateMultiValue(ColumnData $column, string $value): ?ValidationError
    {
        $rules = $this->getPreviewRules($column);

        if (empty($rules)) {
            return null;
        }

        $errors = [];

        foreach (array_filter(array_map('trim', explode(',', $value))) as $item) {
            $error = $this->runValidator($item, $rules);

            if ($error !== null) {
                $errors[$item] = $error;
            }
        }

        return ! empty($errors) ? ValidationError::itemErrors($errors) : null;
    }

    private function validateText(ColumnData $column, string $value): ?ValidationError
    {
        $rules = $this->getPreviewRules($column);

        if (empty($rules)) {
            return null;
        }

        $error = $this->runValidator($value, $rules);

        return $error !== null ? ValidationError::message($error) : null;
    }

    /**
     * Get rules for preview validation (excluding required/nullable).
     *
     * @return array<string>
     */
    private function getPreviewRules(ColumnData $column): array
    {
        return array_filter(
            $column->getRules(),
            fn (string $rule): bool => ! in_array($rule, ['required', 'nullable'], true)
        );
    }

    /**
     * @param  array<int, string>  $rules
     */
    private function runValidator(string $value, array $rules): ?string
    {
        $validator = Validator::make(['value' => $value], ['value' => $rules]);

        if ($validator->passes()) {
            return null;
        }

        /** @var array<int, string> $errors */
        $errors = $validator->errors()->get('value');

        return $errors[0] ?? 'Invalid value';
    }
}
