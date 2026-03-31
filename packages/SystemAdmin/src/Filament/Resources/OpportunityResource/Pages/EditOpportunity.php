<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\OpportunityResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\OpportunityResource;

final class EditOpportunity extends EditRecord
{
    protected static string $resource = OpportunityResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
