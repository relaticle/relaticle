<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\PeopleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource;

final class ListPeople extends ListRecords
{
    protected static string $resource = PeopleResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
