<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TaskResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Relaticle\SystemAdmin\Filament\Resources\TaskResource;

final class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;
}
