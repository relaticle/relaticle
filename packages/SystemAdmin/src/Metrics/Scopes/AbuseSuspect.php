<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics\Scopes;

use App\Enums\BillingStatus;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final readonly class AbuseSuspect implements Scope
{
    /**
     * @param  Builder<Workspace>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        BillingStatus::Trialing->applyToQuery($builder);

        (new TrialFarmer)->apply($builder, $model);
    }
}
