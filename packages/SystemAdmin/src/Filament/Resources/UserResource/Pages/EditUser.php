<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages;

use Filament\Actions\ViewAction;
use Relaticle\SystemAdmin\Filament\Pages\EditCustomerRecord;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Support\QueuePurge;

final class EditUser extends EditCustomerRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            QueuePurge::action(),
        ];
    }
}
