<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics\Scopes;

use App\Enums\StripeSubscriptionStatus;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final readonly class CustomerWorkspace implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        (new ExternalWorkspace)->apply($builder, $model);

        $builder->whereNotIn($model->qualifyColumn('id'), Workspace::query()
            ->withGlobalScope(TrialFarmer::class, new TrialFarmer)
            ->whereDoesntHave('subscriptions', fn (Builder $paying): Builder => $paying->where('stripe_status', StripeSubscriptionStatus::Active->value))
            ->select('workspaces.id'));
    }
}
