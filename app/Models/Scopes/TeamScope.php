<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class TeamScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  Builder<TModel>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereBelongsTo($user->currentTeam);
    }
}
