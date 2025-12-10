<?php

declare(strict_types=1);

namespace App\Filament\Resources\PeopleResource\Pages;

use App\Filament\Exports\PeopleExporter;
use App\Filament\Resources\PeopleResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Size;
use Override;
use Relaticle\CustomFields\Concerns\InteractsWithCustomFields;
use Relaticle\ImportWizard\Filament\Pages\ImportPeople;

final class ListPeople extends ListRecords
{
    use InteractsWithCustomFields;

    protected static string $resource = PeopleResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('import')
                    ->label('Import people')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->url(ImportPeople::getUrl()),
                ExportAction::make()->exporter(PeopleExporter::class),
            ])
                ->icon('heroicon-o-arrows-up-down')
                ->color('gray')
                ->button()
                ->label('Import / Export')
                ->size(Size::Small),
            CreateAction::make()->icon('heroicon-o-plus')->size(Size::Small),
        ];
    }
}
