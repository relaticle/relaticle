<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Filament\Widgets\Concerns\HasPeriodComparison;

/**
 * Signup -> activation -> subscription funnel for the selected period.
 *
 * "Organic sign-up" excludes invited members: a user who accepted an
 * invitation gets a `workspace_user` row for the inviting (unowned) workspace within
 * seconds of registering, so a pivot row created within 24h of the user's
 * own `created_at` marks them as invited rather than organic.
 *
 * "Activated workspace" and "Subscribed workspace" mirror ActivationRateWidget's
 * creator-source filter and the app's own subscription-validity predicate,
 * at workspace grain, restricted to the selected period.
 */
final class FunnelWidget extends StatsOverviewWidget
{
    use HasPeriodComparison;
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        [$currentStart, $currentEnd, $previousStart, $previousEnd] = $this->getPeriodDates();

        $currentSignups = $this->countOrganicSignups($currentStart, $currentEnd);
        $previousSignups = $this->countOrganicSignups($previousStart, $previousEnd);

        $currentActivatedWorkspaces = $this->countActivatedWorkspaces($currentStart, $currentEnd);
        $previousActivatedWorkspaces = $this->countActivatedWorkspaces($previousStart, $previousEnd);

        $currentSubscribedWorkspaces = $this->countSubscribedWorkspaces($currentStart, $currentEnd);
        $previousSubscribedWorkspaces = $this->countSubscribedWorkspaces($previousStart, $previousEnd);

        $activatedDescription = $currentSignups > 0
            ? round($currentActivatedWorkspaces / $currentSignups * 100, 1).'% of sign-ups'
            : 'created a record';

        $subscribedDescription = $currentActivatedWorkspaces > 0
            ? round($currentSubscribedWorkspaces / $currentActivatedWorkspaces * 100, 1).'% of activated'
            : 'this period';

        return [
            $this->buildCountStat('Organic Sign-ups', 'this period', $currentSignups, $previousSignups),
            $this->buildCountStat('Activated Workspaces', $activatedDescription, $currentActivatedWorkspaces, $previousActivatedWorkspaces),
            $this->buildCountStat('Subscribed Workspaces', $subscribedDescription, $currentSubscribedWorkspaces, $previousSubscribedWorkspaces),
        ];
    }

    /**
     * A user counts as an organic sign-up unless they were added to a workspace
     * they do not own within 24h of registering, the signature of accepting
     * an invitation (register -> immediately attached to the inviter's workspace).
     */
    private function countOrganicSignups(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) AS cnt
            FROM users u
            WHERE u.created_at BETWEEN ? AND ?
            AND NOT EXISTS (
                SELECT 1
                FROM workspace_user tu
                INNER JOIN workspaces t ON t.id = tu.workspace_id
                WHERE tu.user_id = u.id
                  AND t.user_id != u.id
                  AND tu.created_at <= u.created_at + INTERVAL '24 hours'
            )
            SQL;

        $row = DB::selectOne($sql, [$start->toDateTimeString(), $end->toDateTimeString()]);

        return (int) ($row->cnt ?? 0);
    }

    /**
     * Reuses HasPeriodComparison::getDistinctActiveColumnValues(), the same
     * helper ActivationRateWidget's getActiveCreatorIds() calls, at workspace
     * grain instead of creator grain, so the source filter only lives in one
     * place.
     */
    private function countActivatedWorkspaces(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $this->getDistinctActiveColumnValues('workspace_id', $start, $end)->count();
    }

    /**
     * Reuses Cashier's own `active` scope (the same predicate
     * SyncWorkspacePlanFromSubscription/HostedWorkspaceAccess rely on via
     * Subscription::valid()) rather than hardcoding a stripe_status list.
     * This app also calls Cashier::keepPastDueSubscriptionsActive(), so the
     * "active" set is wider than a literal ['active', 'trialing'].
     */
    private function countSubscribedWorkspaces(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Subscription::query()
            ->active()
            ->whereBetween('created_at', [$start, $end])
            ->distinct('workspace_id')
            ->count();
    }

    private function buildCountStat(string $label, string $description, int $current, int $previous): Stat
    {
        $change = $this->calculateChange($current, $previous);

        return Stat::make($label, number_format($current))
            ->description("{$description}{$this->formatChange($change)}")
            ->descriptionIcon($change >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger');
    }
}
