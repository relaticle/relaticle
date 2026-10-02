<?php

declare(strict_types=1);

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class RelationFilter implements Filter
{
    public function __construct(private FilterDefinition $definition) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw FilterErrors::at('', __('validation.filter.operator_object', ['name' => $property, 'operator' => $this->definition->operators()[0]]));
        }

        foreach ($value as $operator => $operand) {
            $operator = (string) $operator;

            match ($operator) {
                '$in' => $query->whereHas($property, fn (Builder $related): Builder => $related->whereKey($this->ids($property, $operator, $operand))),
                '$not_in' => $query->whereDoesntHave($property, fn (Builder $related): Builder => $related->whereKey($this->ids($property, $operator, $operand))),
                '$is_empty' => $this->emptiness($query, $property, $operand),
                default => throw FilterErrors::at($operator, __('validation.filter.members_ids_only', ['name' => $property])),
            };
        }
    }

    /**
     * @return list<string>
     */
    private function ids(string $property, string $operator, mixed $operand): array
    {
        $ids = Operand::listOrFail($operand, splitsStrings: true, field: $property, operator: $operator, expected: 'a list of record IDs');

        $invalid = array_find($ids, static fn (string $id): bool => ! Str::isUlid($id));

        if ($invalid !== null) {
            throw FilterErrors::at($operator, __('validation.filter.record_id', ['name' => "{$property} {$operator}", 'value' => $invalid]));
        }

        return $ids;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function emptiness(Builder $query, string $property, mixed $operand): void
    {
        $empty = Operand::boolean($operand) ?? throw FilterErrors::at('$is_empty', __('validation.filter.operand_type', ['name' => "{$property} \$is_empty", 'expected' => 'true or false']));

        $empty ? $query->whereDoesntHave($property) : $query->whereHas($property);
    }
}
