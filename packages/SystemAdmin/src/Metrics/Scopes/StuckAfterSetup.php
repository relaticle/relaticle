<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Relaticle\SystemAdmin\Metrics\ActivityDays;

final readonly class StuckAfterSetup implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $createdAt = $model->qualifyColumn('created_at');

        (new RecentGenuineWorkspace)->apply($builder, $model);

        $builder
            ->whereNotExists(fn (QueryBuilder $activity): QueryBuilder => $activity
                ->fromSub(ActivityDays::query(), 'activity')
                ->whereColumn('activity.workspace_id', $model->qualifyColumn('id'))
                ->whereRaw("activity.day < ({$createdAt})::date + 3"));
    }
}
