<?php

declare(strict_types=1);

namespace App\Queries\Crm;

use App\Data\LinkOption;
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

    /** @return list<array{id: string, label: string, image: ?string, icon: ?string, circular: bool}> */
    public function search(User $user, ?CrmEntity $related, string $search): array
    {
        $term = Operand::string(mb_substr(trim($search), 0, self::MAX_SEARCH_LENGTH));

        if ($term === null) {
            return [];
        }

        $workspace = $user->currentWorkspace;

        if (! $related instanceof CrmEntity) {
            return $user->belongsToWorkspace($workspace)
                ? $this->labelled(User::query()->memberOf($workspace)->whereKeyNot($user->getKey()), 'name', $term)
                : [];
        }

        if (! $user->can('viewAny', $related->model())) {
            return [];
        }

        $records = $related->model()::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->whereBelongsTo($workspace);

        return $this->labelled($records, $related->titleColumn(), $term, $related);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return list<array{id: string, label: string, image: ?string, icon: ?string, circular: bool}>
     */
    private function labelled(Builder $query, string $column, string $term, ?CrmEntity $entity = null): array
    {
        $model = $query->getModel();
        $options = $query
            ->select(LinkOption::columns($model, $column))
            ->with(LinkOption::marks($model))
            ->when($term !== '', fn (Builder $q): Builder => $q->where($column, 'ilike', '%'.LikePattern::escape($term).'%'))
            ->orderBy($column)
            ->orderBy($query->getModel()->getKeyName())
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (Model $record): array => LinkOption::forRecord($record, (string) $record->getAttribute($column), $entity)->toArray())
            ->all();

        return array_values($options);
    }
}
