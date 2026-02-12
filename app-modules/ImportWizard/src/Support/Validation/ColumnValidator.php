<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support\Validation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\NumberFormat;

final class ColumnValidator
{
    public function validate(ColumnData $column, string $value): ?ValidationError
    {
        $type = $column->getType();

        return match (true) {
            $type->isDateOrDateTime() => $this->validateDate($column, $value),
            $type->isFloat() => $this->validateNumber($column, $value),
            $column->isMultiChoiceArbitrary() => $this->validateMultiChoiceArbitrary($column, $value),
            $column->isMultiChoicePredefined() => $this->validateMultiChoicePredefined($column, $value),
            $column->isSingleChoicePredefined() => $this->validateSingleChoice($column, $value),
            default => $this->validateText($column, $value),
        };
    }

    private function validateDate(ColumnData $column, string $value): ?ValidationError
    {
        $format = $column->dateFormat ?? DateFormat::ISO;

        if (! $format->parse($value, $column->getType()->isTimestamp()) instanceof \Carbon\Carbon) {
            return ValidationError::message("Invalid date format. Expected: {$format->getLabel()}");
        }

        return null;
    }

    private function validateNumber(ColumnData $column, string $value): ?ValidationError
    {
        $format = $column->numberFormat ?? NumberFormat::POINT;

        if ($format->parse($value) === null) {
            return ValidationError::message("Invalid number format. Expected: {$format->getLabel()}");
        }

        return null;
    }

    private function validateSingleChoice(ColumnData $column, string $value): ?ValidationError
    {
        $originalValues = $this->getChoiceValues($column);
        $lowercasedValues = $this->lowercaseValues($originalValues);

        if (in_array(mb_strtolower($value), $lowercasedValues, true)) {
            return null;
        }

        return ValidationError::message($this->formatInvalidChoiceMessage($originalValues));
    }

    private function validateMultiChoicePredefined(ColumnData $column, string $value): ?ValidationError
    {
        $lowercasedValues = $this->lowercaseValues($this->getChoiceValues($column));

        $errors = $this->parseCommaSeparated($value)
            ->reject(fn (string $item): bool => in_array(mb_strtolower($item), $lowercasedValues, true))
            ->mapWithKeys(fn (string $item): array => [$item => 'Not a valid option'])
            ->all();

        if ($errors !== []) {
            return ValidationError::itemErrors($errors);
        }

        return null;
    }

    private function validateMultiChoiceArbitrary(ColumnData $column, string $value): ?ValidationError
    {
        $rules = $this->getPreviewRules($column);

        if ($rules === []) {
            return null;
        }

        $errors = $this->parseCommaSeparated($value)
            ->mapWithKeys(fn (string $item): array => [$item => $this->runValidator($item, $rules)])
            ->filter()
            ->all();

        if ($errors !== []) {
            return ValidationError::itemErrors($errors);
        }

        return null;
    }

    private function validateText(ColumnData $column, string $value): ?ValidationError
    {
        $rules = $this->getPreviewRules($column);

        if ($rules === []) {
            return null;
        }

        $error = $this->runValidator($value, $rules);

        if ($error !== null) {
            return ValidationError::message($error);
        }

        return null;
    }

    /** @return Collection<int, non-falsy-string> */
    private function parseCommaSeparated(string $value): Collection
    {
        return str($value)
            ->explode(',')
            ->map(fn (string $v): string => trim($v))
            ->filter()
            ->values();
    }

    /** @return array<int, string> */
    private function getChoiceValues(ColumnData $column): array
    {
        return collect($column->importField->options ?? [])
            ->pluck('value')
            ->all();
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function lowercaseValues(array $values): array
    {
        return array_map(mb_strtolower(...), $values);
    }

    /** @param array<int, string> $validValues */
    private function formatInvalidChoiceMessage(array $validValues): string
    {
        $preview = collect($validValues)->take(5)->implode(', ');
        $suffix = count($validValues) > 5 ? '...' : '';

        return "Invalid choice. Must be one of: {$preview}{$suffix}";
    }

    /** @return array<int, string> */
    private function getPreviewRules(ColumnData $column): array
    {
        return collect($column->getRules())
            ->reject(fn (string $rule): bool => in_array($rule, ['required', 'nullable'], true))
            ->values()
            ->all();
    }

    /** @param array<int, string> $rules */
    private function runValidator(string $value, array $rules): ?string
    {
        $validator = Validator::make(['value' => $value], ['value' => $rules]);

        if ($validator->passes()) {
            return null;
        }

        return $validator->errors()->first('value');
    }
}
