<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages;

use App\Models\Workspace;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Relaticle\SystemAdmin\Filament\Pages\EditCustomerRecord;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\SafeDelete;

final class EditWorkspace extends EditCustomerRecord
{
    protected static string $resource = WorkspaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SafeDelete::action(function (Workspace $record): void {
                resolve(DeletesTeams::class)->delete($record);
            }),
        ];
    }
}
