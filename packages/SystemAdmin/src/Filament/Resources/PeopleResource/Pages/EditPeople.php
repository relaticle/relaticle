<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\PeopleResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource;

final class EditPeople extends EditRecord
{
    protected static string $resource = PeopleResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
