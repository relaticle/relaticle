<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
use App\Enums\PeriodUnit;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\Operand;

final readonly class FilterValueShape
{
    private const int MAX_TEXT_LENGTH = 255;

    /** @return string|int|float|list<string>|array{amount?: int, unit: string}|null */
    public static function of(FilterCondition $condition, FilterFieldKind $kind, mixed $value): string|int|float|array|null
    {
        return match ($condition->valueShape($kind)) {
            'list' => self::list($value),
            'number' => self::number($value),
            'unit', 'period' => self::period($condition, $value),
            'text' => self::text($value),
            default => null,
        };
    }

    private static function number(mixed $value): string|int|float|null
    {
        $text = self::text($value);

        if ($text !== null && preg_match('/^-?\d{1,15}(\.\d{1,15})?$/', $text) === 1) {
            $value = str_contains($text, '.') ? (float) $text : (int) $text;
        }

        if (is_float($value) && is_finite($value) && $value === floor($value) && abs($value) < 1e15) {
            return (int) $value;
        }

        return is_int($value) || (is_float($value) && is_finite($value)) ? $value : $text;
    }

    private static function text(mixed $value): ?string
    {
        $isSafe = is_string($value) && trim($value) !== '' && mb_strlen($value) <= self::MAX_TEXT_LENGTH
            && ! str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8');

        return $isSafe ? $value : null;
    }

    /** @return list<string>|null */
    public static function list(mixed $value): ?array
    {
        if (! is_array($value) || $value === [] || count($value) > CustomFieldFilterSchema::MAX_LIST_VALUES) {
            return null;
        }

        $items = array_map(self::text(...), array_values($value));

        return in_array(null, $items, true) ? null : array_values(array_unique($items));
    }

    /** @return array{amount?: int, unit: string}|null */
    private static function period(FilterCondition $condition, mixed $value): ?array
    {
        $unit = is_array($value) && is_string($value['unit'] ?? null) ? PeriodUnit::tryFrom($value['unit']) : null;

        if (! $unit instanceof PeriodUnit) {
            return null;
        }

        if ($condition === FilterCondition::This) {
            return ['unit' => $unit->value];
        }

        $amount = Operand::integer($value['amount'] ?? null);

        return $amount !== null && $amount >= 1 && $amount <= $unit->maxAmount()
            ? ['amount' => $amount, 'unit' => $unit->value]
            : null;
    }
}
