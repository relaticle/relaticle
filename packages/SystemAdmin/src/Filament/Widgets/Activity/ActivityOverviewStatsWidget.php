<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Activity;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Relaticle\SystemAdmin\Filament\Widgets\Activity\Concerns\InteractsWithActivityTable;

final class ActivityOverviewStatsWidget extends StatsOverviewWidget
{
    use InteractsWithActivityTable;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $total = $this->activityQuery()->count();
        $teams = $this->activityQuery()->whereNotNull('team_id')->distinct()->count('team_id');
        $users = $this->activityQuery()->where('causer_type', 'user')->distinct()->count('causer_id');
        $deletions = $this->activityQuery()->where('event', 'deleted')->count();

        return [
            Stat::make('Total Activities', number_format($total)),
            Stat::make('Active Teams', number_format($teams)),
            Stat::make('Active Users', number_format($users)),
            Stat::make('Deletions', number_format($deletions))
                ->color($deletions > 0 ? 'danger' : 'gray'),
        ];
    }
}
