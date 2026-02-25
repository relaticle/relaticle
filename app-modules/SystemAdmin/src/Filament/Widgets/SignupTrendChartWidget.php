<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;
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

        $groupFormat = $this->getGroupFormat($days);

        $userCountsByBucket = $this->getCountsByBucket(User::query(), $start, $end, $groupFormat);
        $teamCountsByBucket = $this->getCountsByBucket(
            Team::query()->where('personal_team', false),
            $start,
            $end,
            $groupFormat,
        );

        $userCounts = $intervals->map(
            fn (array $interval): int => $userCountsByBucket->get($interval['bucket'], 0)
        )->all();

        $teamCounts = $intervals->map(
            fn (array $interval): int => $teamCountsByBucket->get($interval['bucket'], 0)
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
     * @return Collection<string, int>
     */
    private function getCountsByBucket(
        Builder $query,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $groupFormat,
    ): Collection {
        $bucketExpression = "to_char(created_at, '{$groupFormat}')";

        return $query
            ->selectRaw("{$bucketExpression} as bucket, COUNT(*) as cnt")
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->groupByRaw($bucketExpression)
            ->pluck('cnt', 'bucket')
            ->map(fn (mixed $value): int => (int) $value);
    }

    private function getGroupFormat(int $days): string
    {
        if ($days <= 30) {
            return 'YYYY-MM-DD';
        }

        if ($days <= 90) {
            return 'IYYY-IW';
        }

        return 'YYYY-MM';
    }

    /**
     * @return Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable, bucket: string}>
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
     * @return Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable, bucket: string}>
     */
    private function buildDailyIntervals(CarbonImmutable $start, int $days): Collection
    {
        return collect(range(0, $days - 1))->map(function (int $i) use ($start): array {
            $day = $start->addDays($i);

            return [
                'label' => $day->format('M j'),
                'start' => $day->startOfDay(),
                'end' => $day->endOfDay(),
                'bucket' => $day->format('Y-m-d'),
            ];
        });
    }

    /**
     * @return Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable, bucket: string}>
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
                'bucket' => $current->format('o-W'),
            ]);
            $current = $current->addWeek();
        }

        return $intervals;
    }

    /**
     * @return Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable, bucket: string}>
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
                'bucket' => $current->format('Y-m'),
            ]);
            $current = $current->addMonth();
        }

        return $intervals;
    }
}
