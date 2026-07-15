<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages;

use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource;
use Relaticle\SystemAdmin\Filament\Widgets\Activity\ActivityOverviewStatsWidget;

final class ListActivities extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = ActivityResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ActivityOverviewStatsWidget::class,
        ];
    }
}
