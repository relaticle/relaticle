<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\CompanyResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource;

final class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
