<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Metrics;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Relaticle\Cloud\Admin\Enums\LeadStage;
use Relaticle\SystemAdmin\Metrics\ActivityDays;
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
            ->where(fn (Builder $use): Builder => $use
                ->whereIn('workspaces.id', ActivityDays::workspacesWithOwnData())
                ->orWhereIn('workspaces.id', self::workspacesChattingOnSeveralDays()))
            ->where(function (Builder $statuses) use ($stage): void {
                foreach ($stage->billingStatuses() as $status) {
                    $statuses->orWhere(fn (Builder $matching): Builder => $status->applyToQuery($matching));
                }
            })
            ->select('workspaces.*')
            ->selectRaw('coalesce(workspaces.trial_ends_at, workspaces.pro_trial_used_at + make_interval(days => ?)) as trial_ended_at', [Workspace::PRO_TRIAL_DAYS])
            ->selectSub(self::activity()->selectRaw('count(distinct activity.day)')->where('activity.day', '>=', $since), 'active_days_30')
            ->selectSub(self::activity()->selectRaw('count(*)')->where('activity.kind', 'record'), 'own_records')
            ->selectSub(self::activity()->selectRaw('count(*)')->where('activity.kind', 'message'), 'chat_messages')
            ->selectSub(self::activity()->selectRaw('count(distinct activity.day)')->where('activity.kind', 'message'), 'chat_days')
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

    private static function workspacesChattingOnSeveralDays(): QueryBuilder
    {
        // One day of chat is mostly a first try: 333 such workspaces on 2026-11-01, against 29 that came back.
        return ActivityDays::from()
            ->where('activity.kind', 'message')
            ->groupBy('activity.workspace_id')
            ->havingRaw('count(distinct activity.day) >= 2')
            ->select('activity.workspace_id');
    }

    private static function activity(): QueryBuilder
    {
        return ActivityDays::from()->whereColumn('activity.workspace_id', 'workspaces.id');
    }
}
