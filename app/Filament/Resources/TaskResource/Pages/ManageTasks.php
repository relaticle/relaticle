<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Actions\EnhancedImportAction;
use App\Filament\Imports\TaskImporter;
use App\Filament\Resources\TaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Size;
use Relaticle\CustomFields\Concerns\InteractsWithCustomFields;

final class ManageTasks extends ManageRecords
{
    use InteractsWithCustomFields;

    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EnhancedImportAction::make()
                ->importer(TaskImporter::class)
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->button()
                ->label('Import')
                ->size(Size::Small),
            CreateAction::make()->icon('heroicon-o-plus')->size(Size::Small)->slideOver(),
        ];
    }
}
