<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\SystemAdministratorResource;

final class CreateSystemAdministrator extends CreateRecord
{
    protected static string $resource = SystemAdministratorResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateAction(),
            $this->getCancelAction(),
        ];
    }

    private function getCancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->url($this->getResource()::getUrl('index'))
            ->color('gray');
    }
}
