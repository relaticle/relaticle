<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\PeopleResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource;

final class ViewPeople extends ViewRecord
{
    protected static string $resource = PeopleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->action(null)->hidden(fn (Model $record): bool => $record->getAttribute('merged_into_id') !== null),
        ];
    }
}
