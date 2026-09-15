<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\PeopleResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Relaticle\EmailIntegration\Filament\Concerns\ProvidesComposerToAddress;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource;

final class ViewPeople extends ViewRecord
{
    use ProvidesComposerToAddress;

    protected static string $resource = PeopleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
