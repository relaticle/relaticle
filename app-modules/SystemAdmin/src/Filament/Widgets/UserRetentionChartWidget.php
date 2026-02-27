<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\SystemAdmin\Filament\Widgets\Concerns\HasPeriodComparison;

final class UserRetentionChartWidget extends ChartWidget
{
    use HasPeriodComparison;
    use InteractsWithPageFilters;

    protected static ?int $sort = 6;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return 'User Retention';
    }

    public function getDescription(): string
    {
        return 'New active vs returning users per week.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days);

        $intervals = $this->buildWeeklyIntervals($start, $end);

        $newActive = [];
        $returning = [];
        $labels = [];

        foreach ($intervals as $interval) {
            $labels[] = $interval['label'];

            $activeCreatorIds = $this->getActiveCreatorIds($interval['start'], $interval['end']);

            if ($activeCreatorIds->isEmpty()) {
                $newActive[] = 0;
                $returning[] = 0;

                continue;
            }

            $counts = DB::table('users')
                ->selectRaw('COUNT(*) FILTER (WHERE created_at >= ? AND created_at <= ?) AS new_count', [$interval['start'], $interval['end']])
                ->selectRaw('COUNT(*) FILTER (WHERE created_at < ?) AS returning_count', [$interval['start']])
                ->whereIn('id', $activeCreatorIds)
                ->first();

            $newActive[] = (int) $counts->new_count;
            $returning[] = (int) $counts->returning_count;
        }

        return [
            'datasets' => [
                [
                    'label' => 'New Active',
                    'data' => $newActive,
                    'backgroundColor' => 'rgba(99, 102, 241, 0.8)',
                    'borderColor' => '#6366f1',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Returning',
                    'data' => $returning,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.8)',
                    'borderColor' => '#10b981',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true],
            ],
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
        ];
    }

    /**
     * @return Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function buildWeeklyIntervals(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $intervals = collect();
        $current = $start->startOfWeek();

        while ($current->lt($end)) {
            $weekEnd = $current->endOfWeek()->min($end);
            $intervals->push([
                'label' => $current->format('M j'),
                'start' => $current,
                'end' => $weekEnd,
            ]);
            $current = $current->addWeek();
        }

        return $intervals;
    }
}
