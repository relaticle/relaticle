<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Activity;

use Filament\Widgets\ChartWidget;
use Relaticle\SystemAdmin\Filament\Widgets\Activity\Concerns\InteractsWithActivityTable;

final class TopActiveUsersChartWidget extends ChartWidget
{
    use InteractsWithActivityTable;

    protected static ?int $sort = 4;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return 'Most Active Users';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->activityQuery()
            ->where('causer_type', 'user')
            ->join('users', 'users.id', '=', 'activity_log.causer_id')
            ->toBase()
            ->selectRaw('users.name as name, count(*) as cnt')
            ->groupByRaw('users.name')
            ->orderByRaw('cnt desc')
            ->limit(10)
            ->get();

        return [
            'datasets' => [
                ['label' => 'Activities', 'data' => $rows->pluck('cnt')->map(fn (mixed $v): int => (int) $v)->all()],
            ],
            'labels' => $rows->pluck('name')->map(fn (mixed $v): string => (string) $v)->all(),
        ];
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y'];
    }
}
