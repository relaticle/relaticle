<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final readonly class ExternalWorkspace implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(fn (Builder $external): Builder => $external
            ->whereNull($model->qualifyColumn('user_id'))
            ->orWhereNotIn($model->qualifyColumn('user_id'), InternalWorkspace::ownerIds()));
    }
}
