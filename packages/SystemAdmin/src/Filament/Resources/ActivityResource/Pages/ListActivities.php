<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource;

final class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;
}
