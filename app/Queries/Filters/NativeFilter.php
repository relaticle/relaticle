<?php

declare(strict_types=1);

namespace App\Queries\Filters;

use App\Enums\FilterKind;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\FilterDefinition;
use App\Queries\FilterErrors;
use App\Queries\Operand;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class NativeFilter implements Filter
{
    public function __construct(private FilterDefinition $definition, private ?string $viewerZone) {}

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

            if (in_array('$'.$operator, $operators, true)) {
                throw FilterErrors::sigil($operator, $operator);
            }

            if (! in_array($operator, $operators, true)) {
                throw FilterErrors::at($operator, __('validation.filter.unsupported_operator', ['name' => $property, 'operator' => $operator, 'supported' => implode(', ', $operators)]));
            }

            if ($operator === '$is_empty') {
                $this->emptiness($query, $property, $column, $operand);

                continue;
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
        $text = Operand::string($operand) ?? throw FilterErrors::operand($property, $operator, __('validation.filter.expected.string'));

        match ($operator) {
            '$eq' => $query->where($column, '=', $text),
            '$contains' => $query->where($column, 'ILIKE', '%'.LikePattern::escape($text).'%'),
            '$starts_with' => $query->where($column, 'ILIKE', LikePattern::escape($text).'%'),
            '$ends_with' => $query->where($column, 'ILIKE', '%'.LikePattern::escape($text)),
        };
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function dateTime(Builder $query, string $property, string $column, string $operator, mixed $operand): void
    {
        $date = Operand::date($operand) ?? throw FilterErrors::operand($property, $operator, __('validation.filter.expected.date'));

        if (! is_string($operand) || ! Operand::isBareDate($operand)) {
            Operand::assertOffset($operator, "{$property} {$operator}", $operand, $this->viewerZone);
            $query->where($column, CustomFieldFilterSchema::COMPARISONS[$operator], $date->utc()->toDateTimeString());

            return;
        }

        foreach (Operand::wholeDay($operator, $operand, $this->viewerZone ?? 'UTC') as [$comparison, $instant]) {
            $query->where($column, $comparison, $instant);
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function enum(Builder $query, string $property, string $column, string $operator, mixed $operand): void
    {
        $allowed = $this->definition->enumValues();
        $expected = __('validation.filter.expected.one_of', ['values' => implode(', ', $allowed)]);
        $values = $operator === '$eq'
            ? [$this->single($operand, $property, $operator, $expected)]
            : Operand::listOrFail($operand, field: $property, operator: $operator, expected: $expected);
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
            throw FilterErrors::operand($property, $operator, $expected);
        }

        return $value;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function emptiness(Builder $query, string $property, string $column, mixed $operand): void
    {
        $blankIsEmpty = $this->definition->kind === FilterKind::Text;
        $empty = Operand::boolean($operand) ?? throw FilterErrors::operand($property, '$is_empty', __('validation.filter.expected.boolean'));

        if ($empty) {
            $query->where(fn (Builder $q): Builder => $blankIsEmpty ? $q->whereNull($column)->orWhere($column, '') : $q->whereNull($column));

            return;
        }

        $blankIsEmpty ? $query->whereNotNull($column)->where($column, '<>', '') : $query->whereNotNull($column);
    }
}
