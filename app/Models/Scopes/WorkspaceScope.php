<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class WorkspaceScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  Builder<covariant TModel>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $workspace = resolve(CurrentWorkspace::class)->get();

        if (! $workspace instanceof Workspace) {
            return;
        }

        $builder->whereBelongsTo($workspace);
    }
}
