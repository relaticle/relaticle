<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\PeopleResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Illuminate\Database\Eloquent\Model;
use Override;
use Relaticle\SystemAdmin\Filament\Pages\EditCustomerRecord;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource;

final class EditPeople extends EditCustomerRecord
{
    protected static string $resource = PeopleResource::class;

    #[Override]
    protected function resolveRecord(int|string $key): Model
    {
        $record = parent::resolveRecord($key);

        abort_if($record->getAttribute('merged_into_id') !== null, 403);

        return $record;
    }

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
