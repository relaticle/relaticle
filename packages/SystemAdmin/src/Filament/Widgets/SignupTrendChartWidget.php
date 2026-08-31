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
use Relaticle\SystemAdmin\Filament\Support\ViewerTime;

final class SignupTrendChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '60s';

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
        return 'New users and teams over time, by '.ViewerTime::timezone().' calendar days. The last point is today so far. '.ViewerTime::freshnessCaption();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);

        $timezone = ViewerTime::timezone();
        $groupFormat = $this->getGroupFormat($days);

        /**
         * Intervals run whole buckets in the viewer's calendar, the last one
         * being the bucket today falls in, so the window is derived from them
         * rather than from a bare `now() - N days`, which would start mid-bucket
         * and end a bucket short.
         */
        $intervals = $this->buildIntervals($days);
        $labels = $intervals->pluck('label')->all();

        $start = $intervals->first()['start'];
        $end = ViewerTime::now()->setTimezone('UTC');

        $userCountsByBucket = $this->getCountsByBucket(User::query(), $start, $end, $groupFormat, $timezone);
        $teamCountsByBucket = $this->getCountsByBucket(
            Team::query()->where('personal_team', false),
            $start,
            $end,
            $groupFormat,
            $timezone,
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
     * `created_at` is a UTC-bearing `timestamp without time zone`, so it is
     * relabelled as UTC before being shifted into the viewer's zone. Otherwise
     * a signup just after local midnight buckets into the previous day.
     *
     * @return Collection<string, int>
     */
    private function getCountsByBucket(
        Builder $query,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $groupFormat,
        string $timezone,
    ): Collection {
        return $query
            ->selectRaw(
                "to_char(created_at AT TIME ZONE 'UTC' AT TIME ZONE ?, ?) as bucket, COUNT(*) as cnt",
                [$timezone, $groupFormat],
            )
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            // Grouped by output position: repeating the expression would repeat its
            // placeholders, and Postgres will not match two distinct parameters.
            ->groupByRaw('1')
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
     * @return Collection<int, array{label: string, start: CarbonImmutable, bucket: string}>
     */
    private function buildIntervals(int $days): Collection
    {
        if ($days <= 30) {
            return $this->buildDailyIntervals($days);
        }

        if ($days <= 90) {
            return $this->buildWeeklyIntervals($days);
        }

        return $this->buildMonthlyIntervals($days);
    }

    /**
     * @return Collection<int, array{label: string, start: CarbonImmutable, bucket: string}>
     */
    private function buildDailyIntervals(int $days): Collection
    {
        $first = ViewerTime::today()->subDays($days - 1);

        return collect(range(0, $days - 1))->map(function (int $offset) use ($first): array {
            $day = $first->addDays($offset);

            return [
                'label' => $day->format('M j'),
                'start' => $day->setTimezone('UTC'),
                'bucket' => $day->format('Y-m-d'),
            ];
        });
    }

    /**
     * @return Collection<int, array{label: string, start: CarbonImmutable, bucket: string}>
     */
    private function buildWeeklyIntervals(int $days): Collection
    {
        $today = ViewerTime::today();
        $current = $today->subDays($days - 1)->startOfWeek();

        $intervals = collect();

        while ($current->lte($today)) {
            $intervals->push([
                'label' => $current->format('M j'),
                'start' => $current->setTimezone('UTC'),
                'bucket' => $current->format('o-W'),
            ]);
            $current = $current->addWeek();
        }

        return $intervals;
    }

    /**
     * @return Collection<int, array{label: string, start: CarbonImmutable, bucket: string}>
     */
    private function buildMonthlyIntervals(int $days): Collection
    {
        $today = ViewerTime::today();
        $current = $today->subDays($days - 1)->startOfMonth();

        $intervals = collect();

        while ($current->lte($today)) {
            $intervals->push([
                'label' => $current->format('M Y'),
                'start' => $current->setTimezone('UTC'),
                'bucket' => $current->format('Y-m'),
            ]);
            $current = $current->addMonth();
        }

        return $intervals;
    }
}
