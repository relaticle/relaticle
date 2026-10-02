<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\FilterKind;
use App\Support\LikePattern;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class NativeFilter implements Filter
{
    private const array COMPARISONS = ['$eq' => '=', '$gt' => '>', '$gte' => '>=', '$lt' => '<', '$lte' => '<='];

    public function __construct(private FilterDefinition $definition) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $operators = $this->definition->operators();

        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw FilterErrors::at('', __('validation.filter.operator_object', ['name' => $property, 'operator' => $operators[0]]));
        }

        $column = $query->qualifyColumn($property);

        foreach ($value as $operator => $operand) {
            $operator = (string) $operator;

            if (! str_starts_with($operator, '$') && in_array('$'.$operator, $operators, true)) {
                throw FilterErrors::at($operator, __('validation.filter.operator_sigil', ['operator' => '$'.$operator]));
            }

            if (! in_array($operator, $operators, true)) {
                throw FilterErrors::at($operator, __('validation.filter.unsupported_operator', ['name' => $property, 'operator' => $operator, 'supported' => implode(', ', $operators)]));
            }

            match ($this->definition->kind) {
                FilterKind::Text => $this->text($query, $property, $column, $operator, $operand),
                FilterKind::DateTime => $this->dateTime($query, $property, $column, $operator, $operand),
                default => $this->enum($query, $property, $column, $operator, $operand),
            };
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function text(Builder $query, string $property, string $column, string $operator, mixed $operand): void
    {
        if ($operator === '$is_empty') {
            $this->emptiness($query, $property, $column, $operator, $operand, blankIsEmpty: true);

            return;
        }

        $text = Operand::string($operand) ?? throw FilterErrors::at($operator, __('validation.filter.operand_type', ['name' => "{$property} {$operator}", 'expected' => 'a string']));

        $operator === '$contains'
            ? $query->where($column, 'ILIKE', '%'.LikePattern::escape($text).'%')
            : $query->where($column, '=', $text);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function dateTime(Builder $query, string $property, string $column, string $operator, mixed $operand): void
    {
        if ($operator === '$is_empty') {
            $this->emptiness($query, $property, $column, $operator, $operand, blankIsEmpty: false);

            return;
        }

        $date = Operand::date($operand) ?? throw FilterErrors::at($operator, __('validation.filter.operand_type', ['name' => "{$property} {$operator}", 'expected' => 'a date or date-time']));

        is_string($operand) && Operand::isBareDate($operand)
            ? $query->whereDate($column, self::COMPARISONS[$operator], $date->toDateString())
            : $query->where($column, self::COMPARISONS[$operator], $date->toDateTimeString());
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function enum(Builder $query, string $property, string $column, string $operator, mixed $operand): void
    {
        if ($operator === '$is_empty') {
            $this->emptiness($query, $property, $column, $operator, $operand, blankIsEmpty: false);

            return;
        }

        /** @var class-string<BackedEnum> $enumClass */
        $enumClass = $this->definition->enumClass;
        $allowed = array_map(static fn (BackedEnum $case): string => (string) $case->value, $enumClass::cases());
        $expected = 'one of: '.implode(', ', $allowed);
        $values = $operator === '$eq'
            ? [$this->single($operand, $property, $operator, $expected)]
            : Operand::listOrFail($operand, splitsStrings: true, field: $property, operator: $operator, expected: $expected);
        $unknown = array_first(array_diff($values, $allowed));

        if ($unknown !== null) {
            throw FilterErrors::at($operator, __('validation.filter.enum_value', ['name' => "{$property} {$operator}", 'value' => $unknown, 'values' => implode(', ', $allowed)]));
        }

        match ($operator) {
            '$eq', '$in' => $query->whereIn($column, $values),
            default => $query->where(fn (Builder $excluded): Builder => $excluded->whereNotIn($column, $values)->orWhereNull($column)),
        };
    }

    private function single(mixed $operand, string $property, string $operator, string $expected): string
    {
        $value = Operand::string($operand);

        if ($value === null || str_contains($value, ',')) {
            throw FilterErrors::at($operator, __('validation.filter.operand_type', ['name' => "{$property} {$operator}", 'expected' => $expected]));
        }

        return $value;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function emptiness(Builder $query, string $property, string $column, string $operator, mixed $operand, bool $blankIsEmpty): void
    {
        $empty = Operand::boolean($operand) ?? throw FilterErrors::at($operator, __('validation.filter.operand_type', ['name' => "{$property} {$operator}", 'expected' => 'true or false']));

        if ($empty) {
            $query->where(fn (Builder $q): Builder => $blankIsEmpty ? $q->whereNull($column)->orWhere($column, '') : $q->whereNull($column));

            return;
        }

        $blankIsEmpty ? $query->whereNotNull($column)->where($column, '<>', '') : $query->whereNotNull($column);
    }
}
