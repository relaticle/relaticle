<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\EndTrial;
use Relaticle\SystemAdmin\Filament\Support\ExtendTrial;
use Relaticle\SystemAdmin\Filament\Support\Impersonate;
use Relaticle\SystemAdmin\Filament\Support\ManagedPlan;

final class ViewWorkspace extends ViewRecord
{
    protected static string $resource = WorkspaceResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Impersonate::workspaceOwner(),
            ExtendTrial::action(),
            EndTrial::action(),
            ManagedPlan::startAction(),
            ManagedPlan::withdrawAction(),
            EditAction::make()->action(null),
        ];
    }
}
