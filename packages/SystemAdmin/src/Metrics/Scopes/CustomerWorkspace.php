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
            ->whereDoesntHave('subscriptions', fn (Builder $carded): Builder => $carded->whereIn('stripe_status', [
                StripeSubscriptionStatus::Active->value,
                StripeSubscriptionStatus::Trialing->value,
                StripeSubscriptionStatus::PastDue->value,
            ]))
            ->select('workspaces.id'));
    }
}
