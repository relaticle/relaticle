<?php

declare(strict_types=1);

namespace App\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;

final readonly class Operand
{
    private const string DATE_FORMATS = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})?)?$/';

    /** @return list<string>|null */
    public static function stringList(mixed $operand, bool $splitsStrings): ?array
    {
        if (is_bool($operand)) {
            $operand = self::string($operand);
        }

        if (is_string($operand)) {
            if (! self::isCleanString($operand)) {
                return null;
            }

            $operand = $splitsStrings ? array_map(trim(...), explode(',', $operand)) : [$operand];
        }

        if (! is_array($operand) || $operand === [] || ! array_is_list($operand)) {
            return null;
        }

        $operand = array_map(self::string(...), $operand);

        if (! array_all($operand, static fn (?string $item): bool => $item !== null && $item !== '')) {
            return null;
        }

        return $operand;
    }

    /**
     * @return list<string>
     */
    public static function listOrFail(mixed $operand, string $field, string $operator, string $expected): array
    {
        $name = "{$field} {$operator}";

        $list = self::stringList($operand, true) ?? throw FilterErrors::operand($field, $operator, $expected);

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

        return is_string($operand) && self::isCleanString($operand) ? $operand : null;
    }

    public static function date(mixed $operand): ?CarbonImmutable
    {
        if (! is_string($operand) || preg_match(self::DATE_FORMATS, $operand) !== 1) {
            return null;
        }

        return Validator::make(['date' => $operand], ['date' => ['date']])->passes() ? Date::parse($operand) : null;
    }

    public static function isBareDate(string $operand): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $operand) === 1;
    }

    /**
     * @return list<array{string, string}>
     */
    public static function wholeDay(string $operator, string $date, string $zone): array
    {
        $start = Date::parse($date, $zone);
        $instant = static fn (CarbonImmutable $bound): string => $bound->utc()->toDateTimeString();

        return match ($operator) {
            '$eq' => [['>=', $instant($start)], ['<', $instant($start->addDay())]],
            '$gte' => [['>=', $instant($start)]],
            '$gt' => [['>=', $instant($start->addDay())]],
            '$lt' => [['<', $instant($start)]],
            '$lte' => [['<', $instant($start->addDay())]],
            default => throw new \LogicException("Unsupported whole-day operator [{$operator}]."),
        };
    }

    public static function lacksOffset(mixed $operand): bool
    {
        return is_string($operand) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?$/', $operand) === 1;
    }

    public static function offsetRequired(string $name, string $operand, string $viewerZone): string
    {
        return __('validation.filter.offset_required', [
            'name' => $name,
            'example' => Date::parse($operand, $viewerZone)->format('Y-m-d\TH:i:sP'),
        ]);
    }

    public static function assertOffset(string $path, string $name, mixed $operand, ?ViewerZone $viewerZone): void
    {
        if ($viewerZone?->requiresOffset === true && self::lacksOffset($operand)) {
            throw FilterErrors::at($path, self::offsetRequired($name, (string) $operand, $viewerZone->name));
        }
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

    private static function isCleanString(string $value): bool
    {
        return ! str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8');
    }
}
