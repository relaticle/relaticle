<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;

final class CreateWorkspace extends CreateRecord
{
    protected static string $resource = WorkspaceResource::class;
}
