<?php

declare(strict_types=1);

namespace App\Queries\Crm;

use App\Enums\CrmEntity;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Queries\Operand;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class LinkOptionsQuery
{
    public const int LIMIT = 20;

    private const int MAX_SEARCH_LENGTH = 100;

    /** @return list<array{id: string, label: string}> */
    public function search(User $user, ?CrmEntity $related, string $search): array
    {
        $term = Operand::string(mb_substr(trim($search), 0, self::MAX_SEARCH_LENGTH));

        if ($term === null) {
            return [];
        }

        $workspace = $user->currentWorkspace;

        if (! $related instanceof CrmEntity) {
            return $user->belongsToWorkspace($workspace)
                ? $this->labelled(User::query()->memberOf($workspace), 'name', $term)
                : [];
        }

        if (! $user->can('viewAny', $related->model())) {
            return [];
        }

        $records = $related->model()::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->whereBelongsTo($workspace);

        return $this->labelled($records, $related->titleColumn(), $term);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return list<array{id: string, label: string}>
     */
    private function labelled(Builder $query, string $column, string $term): array
    {
        $options = $query
            ->when($term !== '', fn (Builder $q): Builder => $q->where($column, 'ilike', '%'.LikePattern::escape($term).'%'))
            ->orderBy($column)
            ->orderBy($query->getModel()->getKeyName())
            ->limit(self::LIMIT)
            ->get([$query->getModel()->getKeyName(), $column])
            ->map(static fn (Model $record): array => ['id' => (string) $record->getKey(), 'label' => (string) $record->getAttribute($column)])
            ->all();

        return array_values($options);
    }
}
