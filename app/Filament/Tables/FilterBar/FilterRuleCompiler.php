<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\CustomFieldType;
use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
use App\Enums\PeriodUnit;
use App\Models\CustomField;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\Filters\StaleDaysFilter;
use App\Queries\Operand;
use App\Support\CustomFields\CanonicalValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class FilterRuleCompiler
{
    private const int MAX_PERIOD_AMOUNT = 3650;

    private const string ME = '@me';

    /**
     * @param  array<array-key, mixed>  $rules
     * @param  array<string, FilterField>  $catalog
     * @return array<string, list<array<string, mixed>>>
     */
    public function compile(array $rules, array $catalog, User $user, string $zone): array
    {
        $nodes = [];

        foreach ($rules as $rule) {
            $node = is_array($rule) ? $this->node($rule, $catalog, $user, $zone) : null;

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
    private function node(array $rule, array $catalog, User $user, string $zone): ?array
    {
        $key = $rule['field'] ?? null;
        $condition = FilterCondition::tryFrom(is_string($rule['condition'] ?? null) ? $rule['condition'] : '');
        $field = is_string($key) ? ($catalog[$key] ?? null) : null;

        if (! $field instanceof FilterField || ! $condition instanceof FilterCondition || ! in_array($condition, $field->conditions(), true)) {
            return null;
        }

        $operand = $condition->fixedOperand() ?? $this->valued($condition, $field, $rule['value'] ?? null, $user, $zone);

        if ($operand === null) {
            return null;
        }

        $operators = $condition->isPeriod() && is_array($operand)
            ? array_combine($condition->operators($field->kind), $operand)
            : [$condition->operators($field->kind)[0] => $operand];
        $node = str_starts_with($field->key, FilterFieldCatalog::CUSTOM_FIELD_PREFIX)
            ? ['custom_fields' => [substr($field->key, strlen(FilterFieldCatalog::CUSTOM_FIELD_PREFIX)) => $operators]]
            : [$field->key => $operators];

        return $condition->isNegated($field->kind) ? ['$not' => $node] : $node;
    }

    /** @return string|int|float|list<string>|null */
    private function valued(FilterCondition $condition, FilterField $field, mixed $value, User $user, string $zone): string|int|float|array|null
    {
        return match ($field->kind) {
            FilterFieldKind::Text => $this->text($value),
            FilterFieldKind::MultiText => $this->multiText($condition, $field, $value),
            FilterFieldKind::Number => $this->number($value, $this->isIntegral($field), null, null),
            FilterFieldKind::Inactivity => $this->number($value, true, 1, StaleDaysFilter::MAX_DAYS),
            FilterFieldKind::Choice, FilterFieldKind::MultiChoice => $this->list($value),
            FilterFieldKind::Date => $condition->isPeriod() ? $this->period($condition, $value, $zone) : $this->day($value, $zone),
            FilterFieldKind::Member, FilterFieldKind::Relation, FilterFieldKind::Record => $this->recordIds($field, $value, $user),
            FilterFieldKind::Checkbox => null,
        };
    }

    private function day(mixed $value, string $zone): ?string
    {
        if ($value === '@today') {
            return now($zone)->toDateString();
        }

        $date = is_string($value) && Operand::isBareDate($value) ? Operand::date($value) : null;

        return $date instanceof CarbonImmutable && $date->toDateString() === $value ? $value : null;
    }

    /** @return list<string>|null */
    private function period(FilterCondition $condition, mixed $value, string $zone): ?array
    {
        $unit = is_array($value) && is_string($value['unit'] ?? null) ? PeriodUnit::tryFrom($value['unit']) : null;
        $amount = is_array($value) ? Operand::integer($value['amount'] ?? null) : null;
        $today = now($zone)->startOfDay();

        if (! $unit instanceof PeriodUnit) {
            return null;
        }

        if ($condition === FilterCondition::This) {
            return [$unit->startOf($today)->toDateString(), $unit->endOf($today)->toDateString()];
        }

        if ($amount === null || $amount < 1 || $amount > self::MAX_PERIOD_AMOUNT) {
            return null;
        }

        return $condition === FilterCondition::InThePast
            ? [$unit->minus($today, $amount)->toDateString(), $today->toDateString()]
            : [$today->toDateString(), $unit->plus($today, $amount)->toDateString()];
    }

    /** @return list<string>|null */
    private function recordIds(FilterField $field, mixed $value, User $user): ?array
    {
        $ids = array_map(
            fn (string $id): string => $field->kind === FilterFieldKind::Member && $id === self::ME ? (string) $user->getKey() : $id,
            $this->list($value) ?? [],
        );
        $ids = array_values(array_unique(array_filter($ids, Str::isUlid(...))));

        return $ids === [] ? null : $ids;
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

        return $field->type === CustomFieldType::PHONE && ! $this->isDialable($field, $text) ? null : [$text];
    }

    private function isDialable(FilterField $field, string $text): bool
    {
        return $field->definition instanceof CustomField && CanonicalValue::isPhone($field->definition, $text);
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
        if (! is_array($value) || $value === [] || count($value) > CustomFieldFilterSchema::MAX_LIST_VALUES) {
            return null;
        }

        $strings = array_values(array_filter($value, is_string(...)));

        return $strings === [] ? null : $strings;
    }
}
