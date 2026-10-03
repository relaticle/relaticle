<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Relaticle\SystemAdmin\Enums\LeadStage;
use Relaticle\SystemAdmin\Metrics\Scopes\ExternalWorkspace;

final readonly class SalesLeadsQuery
{
    /**
     * @return Builder<Workspace>
     */
    public static function make(LeadStage $stage): Builder
    {
        $since = now()->subDays(29)->toDateString();

        $query = Workspace::query()
            ->withGlobalScope(ExternalWorkspace::class, new ExternalWorkspace)
            ->whereIn('workspaces.id', ActivityDays::workspacesWithOwnData())
            ->where(function (Builder $statuses) use ($stage): void {
                foreach ($stage->billingStatuses() as $status) {
                    $statuses->orWhere(fn (Builder $matching): Builder => $status->applyToQuery($matching));
                }
            })
            ->select('workspaces.*')
            ->selectRaw('coalesce(workspaces.trial_ends_at, workspaces.pro_trial_used_at + make_interval(days => ?)) as trial_ended_at', [Workspace::PRO_TRIAL_DAYS])
            ->selectSub(self::activity()->selectRaw('count(distinct activity.day)')->where('activity.day', '>=', $since), 'active_days_30')
            ->selectSub(self::activity()->selectRaw('count(*)')->where('activity.kind', 'record'), 'own_records')
            ->selectSub(self::activity()->selectRaw('max(activity.day)'), 'last_active')
            ->selectSub(self::activity()->selectRaw("string_agg(distinct activity.source, ',')")->where('activity.kind', 'record'), 'sources')
            ->selectSub(DB::table('ai_credit_balances')->select('credits_used')->whereColumn('ai_credit_balances.workspace_id', 'workspaces.id')->limit(1), 'credits_used')
            ->withCount('users')
            ->with(['owner', 'subscriptions']);

        return match ($stage) {
            LeadStage::Trialing => $query->orderBy('workspaces.trial_ends_at')->orderByDesc('own_records'),
            LeadStage::TrialEnded => $query->orderByDesc('trial_ended_at')->orderByDesc('own_records'),
            LeadStage::Free => $query->orderByDesc('active_days_30')->orderByDesc('own_records'),
        };
    }

    private static function activity(): QueryBuilder
    {
        return ActivityDays::from()->whereColumn('activity.workspace_id', 'workspaces.id');
    }
}
