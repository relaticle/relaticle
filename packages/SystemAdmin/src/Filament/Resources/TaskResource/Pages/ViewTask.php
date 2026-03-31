<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TaskResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\TaskResource;

final class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
