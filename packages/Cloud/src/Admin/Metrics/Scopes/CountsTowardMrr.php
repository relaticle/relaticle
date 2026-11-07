<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Metrics\Scopes;

use App\Enums\StripeSubscriptionStatus;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Metrics\Scopes\ExternalWorkspace;

/**
 * @implements Scope<Subscription>
 */
final readonly class CountsTowardMrr implements Scope
{
    public function __construct(private ?CarbonImmutable $asOf = null) {}

    /**
     * @param  Builder<covariant Subscription>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $externalWorkspaces = Workspace::query()
            ->withGlobalScope(ExternalWorkspace::class, new ExternalWorkspace)
            ->select('id');

        $builder->whereIn($model->qualifyColumn('workspace_id'), $externalWorkspaces);

        $at = $this->asOf ?? now();

        $builder->where(fn (Builder $live): Builder => $live
            ->whereNull($model->qualifyColumn('ends_at'))
            ->orWhere($model->qualifyColumn('ends_at'), '>', $at));

        if (! $this->asOf instanceof CarbonImmutable) {
            $builder->where($model->qualifyColumn('stripe_status'), StripeSubscriptionStatus::Active->value);

            return;
        }

        $builder
            ->where($model->qualifyColumn('created_at'), '<=', $this->asOf)
            ->where(fn (Builder $trial): Builder => $trial
                ->whereNull($model->qualifyColumn('trial_ends_at'))
                ->orWhere($model->qualifyColumn('trial_ends_at'), '<=', $this->asOf))
            ->whereIn($model->qualifyColumn('stripe_status'), [
                StripeSubscriptionStatus::Active->value,
                StripeSubscriptionStatus::PastDue->value,
                StripeSubscriptionStatus::Canceled->value,
            ]);
    }
}
