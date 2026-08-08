<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Date;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;

final class AiSpendStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 30;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    /**
     * @var list<AiCreditType>
     *
     * Refund/Adjustment rows are ledger artifacts (failed-job rollbacks,
     * sysadmin grants/clawbacks), not consumption — excluding them keeps the
     * "credits this month" figure aligned with the team-level credits_used
     * meter shown on the balances page.
     */
    private const array SPENDABLE_TYPES = [
        AiCreditType::Chat,
        AiCreditType::Summary,
        AiCreditType::Embedding,
    ];

    protected function getStats(): array
    {
        $now = Date::now();
        $monthStart = $now->copy()->startOfMonth();
        $nextMonthStart = $monthStart->copy()->addMonth();
        $lastMonthStart = $monthStart->copy()->subMonth();

        $currentMonthCredits = (int) AiCreditTransaction::query()
            ->whereIn('type', self::SPENDABLE_TYPES)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart)
            ->sum('credits_charged');

        $previousMonthCredits = (int) AiCreditTransaction::query()
            ->whereIn('type', self::SPENDABLE_TYPES)
            ->where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $monthStart)
            ->sum('credits_charged');

        $delta = $currentMonthCredits - $previousMonthCredits;

        // One grouped pass over the month serves both the top-model stat and the
        // dollar-cost stat; they share the same filter and differ only in aggregates.
        $tokenRows = AiCreditTransaction::query()
            ->selectRaw('model, SUM(credits_charged) AS total, SUM(input_tokens) AS input_sum, SUM(output_tokens) AS output_sum')
            ->whereIn('type', self::SPENDABLE_TYPES)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart)
            ->groupBy('model')
            ->get();

        $topModelRow = $tokenRows->sortByDesc(fn (AiCreditTransaction $row): int => (int) $row->total)->first();

        $topModelLabel = $topModelRow !== null
            ? "{$topModelRow->model} ({$topModelRow->total})"
            : '—';

        /** @var array<string, array{input_per_mtok: float, output_per_mtok: float}> $rates */
        $rates = config('chat.model_costs', []);
        $totalCost = 0.0;
        $unpriced = [];

        foreach ($tokenRows as $row) {
            $inputTokens = (int) $row->input_sum;
            $outputTokens = (int) $row->output_sum;

            // Cancelled and timed-out turns settle at the reserved minimum under
            // the synthetic model 'incomplete' with zero tokens. They cost
            // nothing, so listing them as unpriced is a false alarm.
            if ($inputTokens === 0 && $outputTokens === 0) {
                continue;
            }

            $rate = $rates[$row->model] ?? null;

            if (
                ! is_array($rate)
                || ! is_numeric($rate['input_per_mtok'] ?? null)
                || ! is_numeric($rate['output_per_mtok'] ?? null)
            ) {
                $unpriced[] = $row->model;

                continue;
            }

            $totalCost += ($inputTokens / 1_000_000) * $rate['input_per_mtok']
                + ($outputTokens / 1_000_000) * $rate['output_per_mtok'];
        }

        $costDescription = 'Upper bound — prompt caching not deducted';

        if ($unpriced !== []) {
            $costDescription .= '. Unpriced models: '.implode(', ', $unpriced);
        }

        return [
            Stat::make('Credits this month', number_format($currentMonthCredits))
                ->description($monthStart->format('M Y'))
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('primary'),

            Stat::make('Delta vs last month', ($delta >= 0 ? '+' : '').number_format($delta))
                ->description('Previous month: '.number_format($previousMonthCredits))
                ->descriptionIcon($delta >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
                ->color($delta >= 0 ? 'success' : 'danger'),

            Stat::make('Top model', $topModelLabel)
                ->description('Highest credit consumer')
                ->descriptionIcon('heroicon-o-cpu-chip')
                ->color('info'),

            Stat::make('AI cost this month', '$'.number_format($totalCost, 2))
                ->description($costDescription)
                ->descriptionIcon('heroicon-o-currency-dollar')
                ->color('warning'),
        ];
    }
}
