<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\WidgetConfiguration;
use Relaticle\SystemAdmin\Filament\Widgets\InstanceStats;

final class Dashboard extends BaseDashboard
{
    protected static string $routePath = '/instance';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Dashboards';

    protected static ?string $navigationLabel = 'Instance';

    protected static ?int $navigationSort = -1;

    protected static ?string $title = 'Instance dashboard';

    /**
     * @return array<class-string | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [InstanceStats::class];
    }

    public function getColumns(): int
    {
        return 1;
    }
}
