<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Activity;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Widgets\ChartWidget;
use Relaticle\SystemAdmin\Filament\Widgets\Activity\Concerns\InteractsWithActivityTable;

final class ActivityVolumeChartWidget extends ChartWidget
{
    use InteractsWithActivityTable;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return 'Activity Over Time';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        [$start, $end, $unit] = $this->resolveWindow();

        /** @var array<string, int> $counts */
        $counts = $this->activityQuery()
            ->whereBetween('created_at', [$start, $end])
            ->toBase()
            ->selectRaw('date_trunc(?, created_at) as bucket, count(*) as cnt', [$unit])
            ->groupByRaw('bucket')
            ->orderByRaw('bucket')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                CarbonImmutable::parse((string) $row->bucket)->toDateString() => (int) $row->cnt,
            ])
            ->all();

        $labels = [];
        $values = [];
        $step = $unit === 'week' ? '1 week' : '1 day';

        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->add($step)) {
            $key = $cursor->toDateString();
            $labels[] = $cursor->format('M j');
            $values[] = $counts[$key] ?? 0;
        }

        return [
            'datasets' => [
                ['label' => 'Activities', 'data' => $values],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function resolveWindow(): array
    {
        /** @var array<string, mixed> $filter */
        $filter = $this->tableFilters['created_at'] ?? [];

        $end = filled($filter['until'] ?? null)
            ? CarbonImmutable::parse((string) $filter['until'])->endOfDay()
            : CarbonImmutable::now();

        $start = filled($filter['from'] ?? null)
            ? CarbonImmutable::parse((string) $filter['from'])->startOfDay()
            : $end->subDays(30)->startOfDay();

        $unit = $start->diffInDays($end) > 62 ? 'week' : 'day';

        if ($unit === 'week') {
            $start = $start->startOfWeek(CarbonInterface::MONDAY);
        }

        return [$start, $end, $unit];
    }
}
