<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;

#[Description('Per-plan credit utilization and pack-purchase metrics for a month')]
#[Signature('billing:utilization-report {--month= : Month to report, YYYY-MM (default: previous month)}')]
final class UtilizationReportCommand extends Command
{
    public function handle(): int
    {
        $monthOption = $this->option('month');

        $month = is_string($monthOption) && $monthOption !== ''
            ? Date::parse("{$monthOption}-01")
            : now()->subMonthNoOverflow()->startOfMonth();

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->addMonthNoOverflow()->startOfMonth();

        $this->comment("Utilization for {$start->format('F Y')}...");

        $ratiosByPlan = [];

        AiCreditBalance::query()
            ->where('period_starts_at', '<', $end)
            ->where('period_ends_at', '>', $start)
            ->with('team')
            ->chunkById(200, function (Collection $balances) use (&$ratiosByPlan): void {
                foreach ($balances as $balance) {
                    /** @var Team|null $team */
                    $team = $balance->team;

                    if ($team === null) {
                        continue;
                    }

                    $allowance = $team->plan->credits();
                    $ratiosByPlan[$team->plan->value][] = $allowance > 0
                        ? min(100, (int) round($balance->credits_used / $allowance * 100))
                        : 0;
                }
            });

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

        $this->table(['Plan', 'Workspaces', 'p50', 'p90', 'p99', 'At 100%'], $rows);

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
