<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;

final class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
