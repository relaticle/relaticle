<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Relaticle\SystemAdmin\Metrics\ActivityDays;

final readonly class ReachedFirstValue implements Scope
{
    /**
     * @param  Builder<User>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $createdAt = $model->qualifyColumn('created_at');

        (new GenuineSignup)->apply($builder, $model);

        $builder
            ->whereExists(fn (QueryBuilder $exists): QueryBuilder => $exists
                ->fromSub(ActivityDays::query(), 'activity')
                ->where('activity.kind', 'record')
                ->whereColumn('activity.user_id', $model->qualifyColumn('id'))
                ->whereRaw("activity.day <= ({$createdAt})::date + 7"));
    }
}
