<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\CreationSource;
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
 * invitation gets a `team_user` row for the inviting (unowned) team within
 * seconds of registering, so a pivot row created within 24h of the user's
 * own `created_at` marks them as invited rather than organic.
 *
 * "Activated team" and "Subscribed team" mirror ActivationRateWidget's
 * creator-source filter and the app's own subscription-validity predicate,
 * at team grain, restricted to the selected period.
 */
final class FunnelWidget extends StatsOverviewWidget
{
    use HasPeriodComparison;
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        [$currentStart, $currentEnd, $previousStart, $previousEnd] = $this->getPeriodDates();

        $currentSignups = $this->countOrganicSignups($currentStart, $currentEnd);
        $previousSignups = $this->countOrganicSignups($previousStart, $previousEnd);

        $currentActivatedTeams = $this->countActivatedTeams($currentStart, $currentEnd);
        $previousActivatedTeams = $this->countActivatedTeams($previousStart, $previousEnd);

        $currentSubscribedTeams = $this->countSubscribedTeams($currentStart, $currentEnd);
        $previousSubscribedTeams = $this->countSubscribedTeams($previousStart, $previousEnd);

        return [
            $this->buildCountStat('Organic Sign-ups', 'this period', $currentSignups, $previousSignups),
            $this->buildCountStat('Activated Teams', 'created a record', $currentActivatedTeams, $previousActivatedTeams),
            $this->buildCountStat('Subscribed Teams', 'this period', $currentSubscribedTeams, $previousSubscribedTeams),
        ];
    }

    /**
     * A user counts as an organic sign-up unless they were added to a team
     * they do not own within 24h of registering — the signature of accepting
     * an invitation (register -> immediately attached to the inviter's team).
     */
    private function countOrganicSignups(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) AS cnt
            FROM users u
            WHERE u.created_at BETWEEN ? AND ?
            AND NOT EXISTS (
                SELECT 1
                FROM team_user tu
                INNER JOIN teams t ON t.id = tu.team_id
                WHERE tu.user_id = u.id
                  AND t.user_id != u.id
                  AND tu.created_at <= u.created_at + INTERVAL '24 hours'
            )
            SQL;

        $row = DB::selectOne($sql, [$start->toDateTimeString(), $end->toDateTimeString()]);

        return (int) ($row->cnt ?? 0);
    }

    /**
     * Mirrors HasPeriodComparison::getActiveCreatorIds() at team grain: same
     * non-system, non-deleted, period-scoped filter across the entity tables,
     * counted by distinct team_id instead of creator_id.
     */
    private function countActivatedTeams(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $unionParts = [];
        $bindings = [];

        foreach (self::ENTITY_TABLES as $table) {
            $unionParts[] = "SELECT DISTINCT \"team_id\" FROM \"{$table}\" WHERE \"creator_id\" IS NOT NULL AND \"creation_source\" != ? AND \"created_at\" BETWEEN ? AND ? AND \"deleted_at\" IS NULL";
            $bindings[] = CreationSource::SYSTEM->value;
            $bindings[] = $start->toDateTimeString();
            $bindings[] = $end->toDateTimeString();
        }

        $sql = 'SELECT COUNT(DISTINCT team_id) AS cnt FROM ('.implode(' UNION ', $unionParts).') AS activated_teams';

        $row = DB::selectOne($sql, $bindings);

        return (int) ($row->cnt ?? 0);
    }

    /**
     * Reuses Cashier's own `active` scope (the same predicate
     * SyncTeamPlanFromSubscription/HostedWorkspaceAccess rely on via
     * Subscription::valid()) rather than hardcoding a stripe_status list —
     * this app also calls Cashier::keepPastDueSubscriptionsActive(), so the
     * "active" set is wider than a literal ['active', 'trialing'].
     */
    private function countSubscribedTeams(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Subscription::query()
            ->active()
            ->whereBetween('created_at', [$start, $end])
            ->distinct('team_id')
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
