<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CrmEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
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
        private User $user,
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
        $nested = array_diff_key($value, $linkOperators);
        $registry = $nested === [] ? [] : $this->nestedRegistry($property, $nested);
        $in = array_key_exists('$in', $value) ? $this->ids($property, '$in', $value['$in']) : null;

        if ($in !== null || $nested !== []) {
            $query->whereHas($property, function (Builder $related) use ($in, $registry, $nested): void {
                $this->bounded($related);

                if ($in !== null) {
                    $related->whereKey($in);
                }

                if ($nested !== []) {
                    $this->applyNode($related, $registry, $nested);
                }
            });
        }

        if (array_key_exists('$not_in', $value)) {
            $notIn = $this->ids($property, '$not_in', $value['$not_in']);

            $query->whereDoesntHave($property, fn (Builder $related): Builder => $this->bounded($related)->whereKey($notIn));
        }

        if (array_key_exists('$is_empty', $value)) {
            $this->emptiness($query, $property, $value['$is_empty']);
        }
    }

    /**
     * @param  array<array-key, mixed>  $nested
     * @return list<AllowedFilter>
     */
    private function nestedRegistry(string $property, array $nested): array
    {
        $related = $this->definition->related;

        if (! $related instanceof CrmEntity) {
            throw FilterErrors::at((string) array_key_first($nested), __('validation.filter.members_ids_only', ['name' => $property]));
        }

        $stray = array_find_key($nested, static fn (mixed $condition, int|string $key): bool => str_starts_with((string) $key, '$') && ! in_array($key, LogicFilter::KEYWORDS, true));

        if ($stray !== null) {
            throw FilterErrors::at((string) $stray, __('validation.filter.relation_operator', ['name' => $property, 'operator' => $stray]));
        }

        return $this->filters->for($related);
    }

    /**
     * @param  Builder<Model>  $related
     * @return Builder<Model>
     */
    private function bounded(Builder $related): Builder
    {
        return $this->definition->related instanceof CrmEntity
            ? $related->whereBelongsTo($this->user->currentWorkspace)
            : $related;
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

        $inWorkspace = fn (Builder $related): Builder => $this->bounded($related);

        $empty
            ? $query->whereDoesntHave($property, $inWorkspace)
            : $query->whereHas($property, $inWorkspace);
    }
}
