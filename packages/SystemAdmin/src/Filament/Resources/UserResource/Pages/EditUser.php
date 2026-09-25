<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages;

use App\Models\User;
use Filament\Actions\ViewAction;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Relaticle\SystemAdmin\Filament\Pages\EditCustomerRecord;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Support\SafeDelete;

final class EditUser extends EditCustomerRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            SafeDelete::action(function (User $record): void {
                resolve(DeletesUsers::class)->delete($record);
            }),
        ];
    }
}
