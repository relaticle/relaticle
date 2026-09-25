<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages;

use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Relaticle\SystemAdmin\Actions\DeleteSystemAdministrator;
use Relaticle\SystemAdmin\Actions\UpdateSystemAdministrator;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\SystemAdministratorResource;
use Relaticle\SystemAdmin\Filament\Support\SafeDelete;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class EditSystemAdministrator extends EditRecord
{
    protected static string $resource = SystemAdministratorResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return resolve(UpdateSystemAdministrator::class)->execute(auth('sysadmin')->user(), $record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(Arr::prependKeysWith($exception->errors(), 'data.'));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            SafeDelete::action(function (SystemAdministrator $record): void {
                resolve(DeleteSystemAdministrator::class)->execute(auth('sysadmin')->user(), $record);
            }),
        ];
    }
}
