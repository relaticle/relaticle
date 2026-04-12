<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailTemplateResource\Pages;

use App\Filament\Resources\EmailTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Size;
use Override;

final class ManageEmailTemplates extends ManageRecords
{
    protected static string $resource = EmailTemplateResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus')
                ->size(Size::Small)
                ->mutateFormDataUsing(function (array $data): array {
                    $data['team_id'] = filament()->getTenant()?->getKey();
                    $data['created_by'] = auth()->id();

                    return $data;
                }),
        ];
    }
}
