<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides emails whose connected account has been disconnected (soft-deleted).
 *
 * The whereHas runs against the ConnectedAccount relation, so its own
 * SoftDeletingScope applies automatically — a trashed account fails the
 * existence check and its emails drop out of every query. Opt out with
 * ->withoutGlobalScope(ActiveAccountScope::class) for audit/admin views.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final readonly class ActiveAccountScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereHas('connectedAccount');
    }
}
