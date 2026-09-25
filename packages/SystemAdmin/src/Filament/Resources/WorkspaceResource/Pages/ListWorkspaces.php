<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;

final class ListWorkspaces extends ListRecords
{
    protected static string $resource = WorkspaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
