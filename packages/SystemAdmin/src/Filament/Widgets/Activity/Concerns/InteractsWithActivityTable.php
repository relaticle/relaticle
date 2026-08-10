<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Activity\Concerns;

use Filament\Widgets\Concerns\InteractsWithPageTable;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ListActivities;

trait InteractsWithActivityTable
{
    use InteractsWithPageTable;

    protected function getTablePage(): string
    {
        return ListActivities::class;
    }

    /**
     * The List page's filtered query, with ordering stripped for aggregation.
     * TeamScope is already bypassed via ActivityResource::getEloquentQuery().
     */
    protected function activityQuery(): Builder
    {
        return $this->getPageTableQuery()->reorder();
    }
}
