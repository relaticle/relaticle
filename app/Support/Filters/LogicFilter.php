<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CrmEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class LogicFilter implements Filter
{
    use AppliesFilterNodes;

    public const array KEYWORDS = ['$and', '$or', '$not'];

    public function __construct(
        private string $keyword,
        private CrmEntity $entity,
        private EntityFilters $filters,
        private User $user,
    ) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $registry = $this->filters->for($this->entity);

        if ($this->keyword === '$not') {
            $this->complement($query, $registry, $value);

            return;
        }

        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw FilterErrors::at('', __('validation.filter.logic_list', ['keyword' => $this->keyword]));
        }

        $boolean = $this->keyword === '$or' ? 'or' : 'and';

        $query->where(function (Builder $group) use ($registry, $value, $boolean): void {
            foreach ($value as $index => $node) {
                $group->where(function (Builder $branch) use ($registry, $node, $index): void {
                    try {
                        $this->applyNode($branch, $registry, is_array($node) ? $node : []);
                    } catch (ValidationException $exception) {
                        throw FilterErrors::prefix($exception, $index);
                    }
                }, boolean: $boolean);
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<AllowedFilter>  $registry
     */
    private function complement(Builder $query, array $registry, mixed $node): void
    {
        $model = $query->getModel();
        $matching = $model->newQuery()
            ->select($model->getQualifiedKeyName())
            ->whereBelongsTo($this->user->currentWorkspace);

        $this->applyNode($matching, $registry, is_array($node) ? $node : []);

        $query->whereNotExists(fn (QueryBuilder $anti) => $anti
            ->fromSub($matching, 'matched')
            ->whereColumn("matched.{$model->getKeyName()}", $model->getQualifiedKeyName()));
    }
}
