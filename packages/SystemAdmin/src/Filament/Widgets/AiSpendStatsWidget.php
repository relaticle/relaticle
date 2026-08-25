<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\SystemAdmin\Filament\Widgets\Concerns\HasPeriodComparison;

final class AiSpendStatsWidget extends StatsOverviewWidget
{
    use HasPeriodComparison;
    use InteractsWithPageFilters;

    protected static ?int $sort = 30;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    /**
     * @var list<AiCreditType>
     *
     * Refund/Adjustment rows are ledger artifacts (failed-job rollbacks,
     * sysadmin grants/clawbacks), not consumption — excluding them keeps the
     * spend figures consumption-only, matching how the balances page's
     * credits_used meter counts usage.
     */
    private const array SPENDABLE_TYPES = [
        AiCreditType::Chat,
        AiCreditType::Summary,
        AiCreditType::Embedding,
    ];

    protected function getStats(): array
    {
        [$currentStart, $currentEnd, $previousStart, $previousEnd] = $this->getPeriodDates();
        $days = (int) ($this->pageFilters['period'] ?? 30);

        $currentCredits = (int) AiCreditTransaction::query()
            ->whereIn('type', self::SPENDABLE_TYPES)
            ->whereBetween('created_at', [$currentStart, $currentEnd])
            ->sum('credits_charged');

        $previousCredits = (int) AiCreditTransaction::query()
            ->whereIn('type', self::SPENDABLE_TYPES)
            ->whereBetween('created_at', [$previousStart, $previousEnd])
            ->sum('credits_charged');

        $delta = $currentCredits - $previousCredits;

        // One grouped pass over the period serves both the top-model stat and the
        // dollar-cost stat; they share the same filter and differ only in aggregates.
        $tokenRows = AiCreditTransaction::query()
            ->selectRaw('model, SUM(credits_charged) AS total, SUM(input_tokens) AS input_sum, SUM(output_tokens) AS output_sum')
            ->whereIn('type', self::SPENDABLE_TYPES)
            ->whereBetween('created_at', [$currentStart, $currentEnd])
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
            Stat::make('Credits this period', number_format($currentCredits))
                ->description("Last {$days} days")
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('primary'),

            Stat::make('Delta vs previous period', ($delta >= 0 ? '+' : '').number_format($delta))
                ->description('Previous period: '.number_format($previousCredits))
                ->descriptionIcon($delta >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
                ->color($delta >= 0 ? 'success' : 'danger'),

            Stat::make('Top model', $topModelLabel)
                ->description('Highest credit consumer')
                ->descriptionIcon('heroicon-o-cpu-chip')
                ->color('info'),

            Stat::make('AI cost this period', '$'.number_format($totalCost, 2))
                ->description($costDescription)
                ->descriptionIcon('heroicon-o-currency-dollar')
                ->color('warning'),
        ];
    }
}
