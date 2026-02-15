<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Collection;

final class SignupTrendChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    /**
     * @return array<string, mixed>
     */
    public function getColumnSpan(): array
    {
        return [
            'default' => 'full',
            'lg' => 2,
        ];
    }

    public function getHeading(): string
    {
        return 'Signup Trends';
    }

    public function getDescription(): string
    {
        return 'New users and teams over time.';
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days);

        $intervals = $this->buildIntervals($start, $end, $days);
        $labels = $intervals->pluck('label')->toArray();

        $userCounts = $intervals->map(
            fn (array $interval): int => User::query()
                ->whereBetween('created_at', [$interval['start'], $interval['end']])
                ->count()
        )->all();

        $teamCounts = $intervals->map(
            fn (array $interval): int => Team::query()
                ->where('personal_team', false)
                ->whereBetween('created_at', [$interval['start'], $interval['end']])
                ->count()
        )->all();

        return [
            'datasets' => [
                [
                    'label' => 'New Users',
                    'data' => $userCounts,
                    'borderColor' => '#6366f1',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 3,
                ],
                [
                    'label' => 'New Teams',
                    'data' => $teamCounts,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * @return Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function buildIntervals(CarbonImmutable $start, CarbonImmutable $end, int $days): Collection
    {
        if ($days <= 30) {
            return $this->buildDailyIntervals($start, $days);
        }

        if ($days <= 90) {
            return $this->buildWeeklyIntervals($start, $end);
        }

        return $this->buildMonthlyIntervals($start, $end);
    }

    /**
     * @return Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function buildDailyIntervals(CarbonImmutable $start, int $days): Collection
    {
        return collect(range(0, $days - 1))->map(fn (int $i): array => [
            'label' => $start->addDays($i)->format('M j'),
            'start' => $start->addDays($i)->startOfDay(),
            'end' => $start->addDays($i)->endOfDay(),
        ]);
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

    /**
     * @return Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function buildMonthlyIntervals(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $intervals = collect();
        $current = $start->startOfMonth();

        while ($current->lt($end)) {
            $monthEnd = $current->endOfMonth()->min($end);
            $intervals->push([
                'label' => $current->format('M Y'),
                'start' => $current,
                'end' => $monthEnd,
            ]);
            $current = $current->addMonth();
        }

        return $intervals;
    }
}
