<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final readonly class InternalWorkspace implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereIn($model->qualifyColumn('user_id'), self::ownerIds());
    }

    public static function ownerIds(): QueryBuilder
    {
        return DB::table('users')
            ->select('users.id')
            ->whereIn(DB::raw('lower(users.email)'), DB::table('system_administrators')->selectRaw('lower(system_administrators.email)'));
    }
}
