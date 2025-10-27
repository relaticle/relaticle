<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\CreationSource;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

final class SalesAnalyticsChartWidget extends ChartWidget
{
    protected static ?int $sort = 2;

    /**
     * @return array<string, mixed>
     */
    public function getColumnSpan(): array
    {
        return [
            'default' => 'full',
            'md' => 2,
            'lg' => 3,
            'xl' => 3,
            '2xl' => 3,
        ];
    }

    public function getHeading(): string
    {
        return 'Sales Pipeline Trends';
    }

    public function getDescription(): string
    {
        return 'Track your sales pipeline value and opportunities count over the last 6 months.';
    }

    public function getMaxHeight(): string
    {
        return '300px';
    }

    protected function getData(): array
    {
        $salesData = $this->getSalesData();

        return [
            'datasets' => [
                [
                    'label' => 'Pipeline Value ($)',
                    'data' => $salesData['monthly_values'],
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'borderColor' => '#10B981',
                    'borderWidth' => 3,
                    'fill' => true,
                    'tension' => 0.4,
                    'pointBackgroundColor' => '#10B981',
                    'pointBorderColor' => '#ffffff',
                    'pointBorderWidth' => 2,
                    'pointRadius' => 6,
                    'pointHoverRadius' => 8,
                ],
                [
                    'label' => 'Opportunities Count',
                    'data' => $salesData['monthly_counts'],
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'borderColor' => '#3B82F6',
                    'borderWidth' => 3,
                    'fill' => true,
                    'tension' => 0.4,
                    'pointBackgroundColor' => '#3B82F6',
                    'pointBorderColor' => '#ffffff',
                    'pointBorderWidth' => 2,
                    'pointRadius' => 6,
                    'pointHoverRadius' => 8,
                    'yAxisID' => 'y1',
                ],
            ],
            'labels' => $salesData['months'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array{months: array<int, string>, monthly_values: array<int, float>, monthly_counts: array<int, int>}
     */
    private function getSalesData(): array
    {
        $monthlyData = collect(range(5, 0))
            ->map($this->getMonthData(...))
            ->values();

        return [
            'months' => $monthlyData->pluck('month')->toArray(),
            'monthly_values' => $monthlyData->pluck('value')->toArray(),
            'monthly_counts' => $monthlyData->pluck('count')->toArray(),
        ];
    }

    /**
     * @return array{month: string, value: float, count: int}
     */
    private function getMonthData(int $monthsAgo): array
    {
        $month = now()->subMonths($monthsAgo);
        $monthStart = $month->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $monthData = DB::table('opportunities')
            ->leftJoin('custom_field_values as cfv_amount', fn (mixed $join) => $join->on('opportunities.id', '=', 'cfv_amount.entity_id')
                ->where('cfv_amount.entity_type', 'opportunity')
            )
            ->leftJoin('custom_fields as cf_amount', fn (mixed $join) => $join->on('cfv_amount.custom_field_id', '=', 'cf_amount.id')
                ->where('cf_amount.code', 'amount')
            )
            ->whereNull('opportunities.deleted_at')
            ->where('opportunities.creation_source', '!=', CreationSource::SYSTEM->value)
            ->whereBetween('opportunities.created_at', [$monthStart, $monthEnd])
            ->select('cfv_amount.float_value as amount')
            ->get();

        return [
            'month' => $month->format('M Y'),
            'value' => $monthData->sum('amount') ?? 0,
            'count' => $monthData->count(),
        ];
    }
}
