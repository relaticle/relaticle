<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TaskResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\TaskResource;

final class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
