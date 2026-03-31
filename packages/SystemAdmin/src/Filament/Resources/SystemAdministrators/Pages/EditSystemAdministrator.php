<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\SystemAdministratorResource;

final class EditSystemAdministrator extends EditRecord
{
    protected static string $resource = SystemAdministratorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
