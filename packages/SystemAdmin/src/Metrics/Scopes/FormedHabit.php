<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics\Scopes;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Relaticle\SystemAdmin\Metrics\ActivityDays;

final readonly class FormedHabit implements Scope
{
    public function __construct(private ?CarbonImmutable $weekStart = null) {}

    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $end = $this->weekStart ?? now()->startOfWeek(CarbonInterface::MONDAY);

        $builder
            ->whereIn($model->qualifyColumn('id'), ActivityDays::from()
                ->select('activity.workspace_id')
                ->where('activity.day', '>=', $end->subWeeks(4)->toDateString())
                ->where('activity.day', '<', $end->toDateString())
                ->groupBy('activity.workspace_id')
                ->havingRaw("count(distinct date_trunc('week', activity.day)) >= 3"));

        (new ExternalWorkspace)->apply($builder, $model);
    }
}
