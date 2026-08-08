<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Plan;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;

#[Description('Per-plan credit utilization and pack-purchase metrics for a month, sourced from the credit ledger')]
#[Signature('billing:utilization-report {--month= : Month to report, YYYY-MM (default: previous month)}')]
final class UtilizationReportCommand extends Command
{
    /**
     * @var list<AiCreditType>
     *
     * Mirrors AiSpendStatsWidget::SPENDABLE_TYPES in the SystemAdmin package
     * (duplicated rather than imported -- App must not depend on
     * Relaticle\SystemAdmin). Refund/Adjustment/Reservation/Purchase rows are
     * ledger artifacts, not consumption of the monthly allowance.
     */
    private const array SPENDABLE_TYPES = [
        AiCreditType::Chat,
        AiCreditType::Summary,
        AiCreditType::Embedding,
    ];

    public function handle(): int
    {
        $monthOption = $this->option('month');

        if (is_string($monthOption) && $monthOption !== '') {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthOption)) {
                $this->error("Invalid --month value \"{$monthOption}\": expected format YYYY-MM (e.g. 2026-07).");

                return self::FAILURE;
            }

            $month = Date::parse("{$monthOption}-01");
        } else {
            $month = now()->subMonthNoOverflow()->startOfMonth();
        }

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->addMonthNoOverflow()->startOfMonth();

        $this->comment("Utilization for {$start->format('F Y')}...");

        // ai_credit_balances holds one upserted row per team -- resetPeriod()
        // overwrites it in place every billing period, so it has no history.
        // The ledger (ai_credit_transactions) is append-only and does, so the
        // report is sourced from it exclusively.
        $usageByTeam = AiCreditTransaction::query()
            ->whereIn('type', self::SPENDABLE_TYPES)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('team_id, SUM(credits_charged) AS credits_used')
            ->groupBy('team_id')
            ->pluck('credits_used', 'team_id');

        $teamIds = $usageByTeam->keys()->all();

        $latestResetByTeam = AiCreditTransaction::query()
            ->whereIn('team_id', $teamIds)
            ->where('type', AiCreditType::Adjustment)
            ->where('created_at', '<', $end)
            ->latest()
            ->get(['team_id', 'metadata'])
            ->filter(fn (AiCreditTransaction $row): bool => is_array($row->metadata) && ($row->metadata['action'] ?? null) === 'reset_period')
            ->unique('team_id')
            ->keyBy('team_id');

        $teamsById = Team::query()->whereIn('id', $teamIds)->get()->keyBy(fn (Team $team): string => $team->getKey());

        $ratiosByPlan = [];
        $fallbackCount = 0;

        foreach ($teamIds as $teamId) {
            $resolved = $this->resolvePlanAndAllowance($latestResetByTeam->get($teamId), $teamsById->get($teamId));

            if ($resolved === null) {
                continue;
            }

            [$plan, $allowance, $usedFallback] = $resolved;

            if ($usedFallback) {
                $fallbackCount++;
            }

            // Deliberately unclamped: prepaid packs let a workspace spend well
            // past its monthly allowance, and how far past is exactly the demand
            // signal this report exists to size packs and tiers against.
            $creditsUsed = (int) $usageByTeam[$teamId];
            $ratiosByPlan[$plan->value][] = (int) round($creditsUsed / $allowance * 100);
        }

        ksort($ratiosByPlan);

        $rows = [];

        foreach ($ratiosByPlan as $plan => $ratios) {
            sort($ratios);
            $rows[] = [
                $plan,
                count($ratios),
                $this->percentile($ratios, 50).'%',
                $this->percentile($ratios, 90).'%',
                $this->percentile($ratios, 99).'%',
                count(array_filter($ratios, fn (int $r): bool => $r >= 100)),
            ];
        }

        $this->comment('Note: rows below cover only workspaces with recorded usage in the reported month. Workspaces on an active plan with zero usage that month are not represented.');
        $this->table(['Plan', 'Workspaces', 'p50', 'p90', 'p99', 'At/over 100%'], $rows);

        if ($fallbackCount > 0) {
            $this->comment("{$fallbackCount} workspace(s) had no historical period-reset record for {$start->format('F Y')}; used their current plan allowance as a fallback.");
        }

        $packBuyers = AiCreditTransaction::query()
            ->where('type', AiCreditType::Purchase)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('team_id, COUNT(*) AS purchases, SUM(credits_charged) AS credits')
            ->groupBy('team_id')
            ->get();

        $this->table(
            ['Pack buyers', 'Purchases', 'Credits sold', 'Repeat buyers'],
            [[
                $packBuyers->count(),
                (int) $packBuyers->sum('purchases'),
                (int) $packBuyers->sum('credits'),
                $packBuyers->where('purchases', '>', 1)->count(),
            ]],
        );

        return self::SUCCESS;
    }

    /**
     * The team's plan and monthly allowance as they stood during the
     * reported period: read from its most recent `reset_period` ledger row
     * (Task 5's CreditService::resetPeriod() writes `metadata.plan` and
     * `metadata.allowance_granted`), falling back to the team's current plan
     * when no such row exists yet (a team created mid-window, before its
     * first period reset).
     *
     * @return array{0: Plan, 1: int, 2: bool}|null
     */
    private function resolvePlanAndAllowance(?AiCreditTransaction $resetRow, ?Team $team): ?array
    {
        $metadata = $resetRow?->metadata;
        $historicalPlan = is_array($metadata) ? Plan::tryFrom((string) ($metadata['plan'] ?? '')) : null;
        $historicalAllowance = is_array($metadata) ? ($metadata['allowance_granted'] ?? null) : null;

        if ($historicalPlan !== null && is_int($historicalAllowance) && $historicalAllowance > 0) {
            return [$historicalPlan, $historicalAllowance, false];
        }

        if (! $team instanceof Team) {
            return null;
        }

        return [$team->plan, $team->plan->credits(), true];
    }

    /** @param list<int> $sorted */
    private function percentile(array $sorted, int $p): int
    {
        if ($sorted === []) {
            return 0;
        }

        $index = (int) ceil($p / 100 * count($sorted)) - 1;

        return $sorted[max(0, min($index, count($sorted) - 1))];
    }
}
