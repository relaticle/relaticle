<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Activity;

use Filament\Widgets\ChartWidget;
use Relaticle\SystemAdmin\Filament\Widgets\Activity\Concerns\InteractsWithActivityTable;

final class TopActiveTeamsChartWidget extends ChartWidget
{
    use InteractsWithActivityTable;

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return 'Most Active Teams';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->activityQuery()
            ->whereNotNull('team_id')
            ->join('teams', 'teams.id', '=', 'activity_log.team_id')
            ->toBase()
            ->selectRaw('teams.name as name, count(*) as cnt')
            ->groupByRaw('teams.name')
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
