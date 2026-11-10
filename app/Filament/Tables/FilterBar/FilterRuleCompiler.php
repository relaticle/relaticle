<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\CustomFieldType;
use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\Filters\StaleDaysFilter;

final readonly class FilterRuleCompiler
{
    /**
     * @param  array<array-key, mixed>  $rules
     * @param  array<string, FilterField>  $catalog
     * @return array<string, list<array<string, mixed>>>
     */
    public function compile(array $rules, array $catalog, User $user, string $zone): array
    {
        $nodes = [];

        foreach ($rules as $rule) {
            $node = is_array($rule) ? $this->node($rule, $catalog) : null;

            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes === [] ? [] : ['$and' => $nodes];
    }

    /**
     * @param  array<array-key, mixed>  $rule
     * @param  array<string, FilterField>  $catalog
     * @return array<string, mixed>|null
     */
    private function node(array $rule, array $catalog): ?array
    {
        $key = $rule['field'] ?? null;
        $condition = FilterCondition::tryFrom(is_string($rule['condition'] ?? null) ? $rule['condition'] : '');
        $field = is_string($key) ? ($catalog[$key] ?? null) : null;

        if (! $field instanceof FilterField || ! $condition instanceof FilterCondition || ! in_array($condition, $field->conditions(), true)) {
            return null;
        }

        $operand = $condition->fixedOperand() ?? $this->valued($condition, $field, $rule['value'] ?? null);

        if ($operand === null) {
            return null;
        }

        $operators = [$condition->operators($field->kind)[0] => $operand];
        $node = str_starts_with($field->key, FilterFieldCatalog::CUSTOM_FIELD_PREFIX)
            ? ['custom_fields' => [substr($field->key, strlen(FilterFieldCatalog::CUSTOM_FIELD_PREFIX)) => $operators]]
            : [$field->key => $operators];

        return $condition->isNegated($field->kind) ? ['$not' => $node] : $node;
    }

    /** @return string|int|float|list<string>|null */
    private function valued(FilterCondition $condition, FilterField $field, mixed $value): string|int|float|array|null
    {
        return match ($field->kind) {
            FilterFieldKind::Text => $this->text($value),
            FilterFieldKind::MultiText => $this->multiText($condition, $field, $value),
            FilterFieldKind::Number => $this->number($value, $this->isIntegral($field), null, null),
            FilterFieldKind::Inactivity => $this->number($value, true, 1, StaleDaysFilter::MAX_DAYS),
            FilterFieldKind::Choice, FilterFieldKind::MultiChoice => $this->list($value),
            FilterFieldKind::Checkbox, FilterFieldKind::Date, FilterFieldKind::Member, FilterFieldKind::Relation, FilterFieldKind::Record => null,
        };
    }

    private function isIntegral(FilterField $field): bool
    {
        return $field->type instanceof CustomFieldType
            && (CustomFieldFilterSchema::operatorsForType($field->type->value)['$eq']['type'] ?? null) === 'integer';
    }

    private function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }

    /** @return string|list<string>|null */
    private function multiText(FilterCondition $condition, FilterField $field, mixed $value): string|array|null
    {
        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        if (! $condition->takesList($field->kind)) {
            return $text;
        }

        return $field->type === CustomFieldType::PHONE && ! str_starts_with($text, '+') ? null : [$text];
    }

    private function number(mixed $value, bool $integral, ?int $min, ?int $max): int|float|null
    {
        $number = is_string($value) ? trim($value) : $value;

        if (! is_int($number) && ! is_float($number) && ! (is_string($number) && is_numeric($number))) {
            return null;
        }

        $number += 0;

        if (is_float($number) && is_finite($number) && floor($number) === $number && abs($number) < PHP_INT_MAX) {
            $number = (int) $number;
        }

        $valid = is_int($number) ? true : (! $integral && is_finite($number));

        return $valid && ($min === null || $number >= $min) && ($max === null || $number <= $max) ? $number : null;
    }

    /** @return list<string>|null */
    private function list(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $strings = array_values(array_filter($value, is_string(...)));

        return $strings === [] ? null : $strings;
    }
}
