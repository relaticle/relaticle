<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Mcp\Schema\CustomFieldFilterSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;

final readonly class Operand
{
    /** @return list<string>|null */
    public static function stringList(mixed $operand, bool $splitsStrings): ?array
    {
        if (is_string($operand)) {
            $operand = $splitsStrings ? array_map(trim(...), explode(',', $operand)) : [$operand];
        }

        if (! is_array($operand) || $operand === [] || ! array_is_list($operand)) {
            return null;
        }

        $operand = array_map(static fn (mixed $item): mixed => is_bool($item) ? ($item ? 'true' : 'false') : $item, $operand);

        if (! array_all($operand, static fn (mixed $item): bool => is_string($item) && $item !== '')) {
            return null;
        }

        return $operand;
    }

    /**
     * @return list<string>
     */
    public static function listOrFail(mixed $operand, bool $splitsStrings, string $field, string $operator, string $expected): array
    {
        $name = "{$field} {$operator}";

        $list = self::stringList($operand, $splitsStrings)
            ?? throw FilterErrors::at($operator, __('validation.filter.operand_type', ['name' => $name, 'expected' => $expected]));

        if (count($list) > CustomFieldFilterSchema::MAX_LIST_VALUES) {
            throw FilterErrors::at($operator, __('validation.filter.too_many_values', ['name' => $name, 'max' => CustomFieldFilterSchema::MAX_LIST_VALUES]));
        }

        return $list;
    }

    public static function string(mixed $operand): ?string
    {
        // Spatie turns the query-string values true and false into booleans before any filter runs.
        if (is_bool($operand)) {
            return $operand ? 'true' : 'false';
        }

        return is_string($operand) ? $operand : null;
    }

    public static function date(mixed $operand): ?CarbonImmutable
    {
        if (! is_string($operand) || Validator::make(['date' => $operand], ['date' => ['date']])->fails()) {
            return null;
        }

        // Postgres rejects some strings PHP parses ("Jan 1st 2026"), so only Carbon's canonical form reaches the query.
        return Date::parse($operand);
    }

    public static function isBareDate(string $operand): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $operand) === 1;
    }

    public static function boolean(mixed $operand): ?bool
    {
        if (is_bool($operand)) {
            return $operand;
        }

        if (! is_string($operand) && ! is_int($operand)) {
            return null;
        }

        return filter_var($operand, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public static function integer(mixed $operand): ?int
    {
        if (is_int($operand)) {
            return $operand;
        }

        $integer = is_string($operand) ? filter_var($operand, FILTER_VALIDATE_INT) : false;

        return $integer === false ? null : $integer;
    }

    public static function number(mixed $operand): int|float|null
    {
        if (is_int($operand) || is_float($operand)) {
            return $operand;
        }

        $number = is_string($operand) ? filter_var($operand, FILTER_VALIDATE_FLOAT) : false;

        return $number === false ? null : $number;
    }
}
