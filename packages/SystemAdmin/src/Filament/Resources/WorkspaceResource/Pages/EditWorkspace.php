<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages;

use Relaticle\SystemAdmin\Filament\Pages\EditCustomerRecord;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\QueuePurge;

final class EditWorkspace extends EditCustomerRecord
{
    protected static string $resource = WorkspaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            QueuePurge::action(),
        ];
    }
}
