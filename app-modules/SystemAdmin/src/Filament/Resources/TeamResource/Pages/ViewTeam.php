<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;

final class ViewTeam extends ViewRecord
{
    protected static string $resource = TeamResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
