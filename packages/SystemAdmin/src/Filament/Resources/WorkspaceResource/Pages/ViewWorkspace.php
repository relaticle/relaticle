<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\Impersonate;

final class ViewWorkspace extends ViewRecord
{
    protected static string $resource = WorkspaceResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Impersonate::workspaceOwner(),
            EditAction::make()->action(null),
        ];
    }
}
