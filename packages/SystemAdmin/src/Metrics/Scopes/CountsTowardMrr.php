<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics\Scopes;

use App\Enums\StripeSubscriptionStatus;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Laravel\Cashier\Subscription;

final readonly class CountsTowardMrr implements Scope
{
    public function __construct(private ?CarbonImmutable $asOf = null) {}

    /**
     * @param  Builder<Subscription>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $externalWorkspaces = Workspace::query()->select('id');
        (new ExternalWorkspace)->apply($externalWorkspaces, $externalWorkspaces->getModel());

        $builder->whereIn($model->qualifyColumn('workspace_id'), $externalWorkspaces);

        if (! $this->asOf instanceof CarbonImmutable) {
            $builder->active();

            return;
        }

        $builder
            ->where($model->qualifyColumn('created_at'), '<=', $this->asOf)
            ->where(fn (Builder $live): Builder => $live
                ->whereNull($model->qualifyColumn('ends_at'))
                ->orWhere($model->qualifyColumn('ends_at'), '>', $this->asOf))
            ->whereNotIn($model->qualifyColumn('stripe_status'), [
                StripeSubscriptionStatus::Incomplete->value,
                StripeSubscriptionStatus::IncompleteExpired->value,
                StripeSubscriptionStatus::Unpaid->value,
            ]);
    }
}
