<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
use App\Models\User;

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

        $operand = $this->operand($condition, $field->kind, $rule['value'] ?? null);

        if ($operand === null) {
            return null;
        }

        $operators = [$condition->operators($field->kind)[0] => $operand];
        $node = str_starts_with($field->key, FilterFieldCatalog::CUSTOM_FIELD_PREFIX)
            ? ['custom_fields' => [substr($field->key, strlen(FilterFieldCatalog::CUSTOM_FIELD_PREFIX)) => $operators]]
            : [$field->key => $operators];

        return $condition->isNegated() ? ['$not' => $node] : $node;
    }

    /** @return bool|string|int|float|list<string>|null */
    private function operand(FilterCondition $condition, FilterFieldKind $kind, mixed $value): bool|string|int|float|array|null
    {
        return match ($condition) {
            FilterCondition::IsEmpty, FilterCondition::IsChecked, FilterCondition::IsNotChecked => true,
            FilterCondition::IsNotEmpty => false,
            default => $this->valued($condition, $kind, $value),
        };
    }

    /** @return string|int|float|list<string>|null */
    private function valued(FilterCondition $condition, FilterFieldKind $kind, mixed $value): string|int|float|array|null
    {
        return match ($kind) {
            FilterFieldKind::Text => $this->text($value),
            FilterFieldKind::MultiText => $this->multiText($condition, $value),
            FilterFieldKind::Number, FilterFieldKind::Inactivity => $this->number($value),
            FilterFieldKind::Choice, FilterFieldKind::MultiChoice => $this->list($value),
            FilterFieldKind::Checkbox, FilterFieldKind::Date, FilterFieldKind::Member, FilterFieldKind::Relation, FilterFieldKind::Record => null,
        };
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return string|list<string>|null */
    private function multiText(FilterCondition $condition, mixed $value): string|array|null
    {
        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        return in_array($condition, [FilterCondition::Is, FilterCondition::IsNot], true) ? [$text] : $text;
    }

    private function number(mixed $value): int|float|null
    {
        return is_numeric($value) ? $value + 0 : null;
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
