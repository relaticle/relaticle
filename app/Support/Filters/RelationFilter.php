<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CrmEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class RelationFilter implements Filter
{
    use AppliesFilterNodes;

    public function __construct(
        private FilterDefinition $definition,
        private EntityFilters $filters,
    ) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw FilterErrors::at('', __('validation.filter.operator_object', ['name' => $property, 'operator' => $this->definition->operators()[0]]));
        }

        $linkOperators = array_flip(FilterDefinition::LINK_OPERATORS);

        foreach (array_intersect_key($value, $linkOperators) as $operator => $operand) {
            $operator = (string) $operator;

            match ($operator) {
                '$in' => $query->whereHas($property, fn (Builder $related): Builder => $related->whereKey($this->ids($property, $operator, $operand))),
                '$not_in' => $query->whereDoesntHave($property, fn (Builder $related): Builder => $related->whereKey($this->ids($property, $operator, $operand))),
                default => $this->emptiness($query, $property, $operand),
            };
        }

        $nested = array_diff_key($value, $linkOperators);

        if ($nested === []) {
            return;
        }

        if (! $this->definition->related instanceof CrmEntity) {
            throw FilterErrors::at((string) array_key_first($nested), __('validation.filter.members_ids_only', ['name' => $property]));
        }

        $stray = array_find_key($nested, static fn (mixed $condition, int|string $key): bool => str_starts_with((string) $key, '$') && ! in_array($key, LogicFilter::KEYWORDS, true));

        if ($stray !== null) {
            throw FilterErrors::at((string) $stray, __('validation.filter.members_ids_only', ['name' => $property]));
        }

        $registry = $this->filters->for($this->definition->related);

        $query->whereHas($property, function (Builder $related) use ($registry, $nested): void {
            $this->applyNode($related, $registry, $nested);
        });
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

        return array_map(strtolower(...), $ids);
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
