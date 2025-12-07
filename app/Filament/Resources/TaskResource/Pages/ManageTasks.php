<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Pages\Import\ImportTasks;
use App\Filament\Resources\TaskResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Size;
use Override;
use Relaticle\CustomFields\Concerns\InteractsWithCustomFields;

final class ManageTasks extends ManageRecords
{
    use InteractsWithCustomFields;

    protected static string $resource = TaskResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->button()
                ->size(Size::Small)
                ->url(ImportTasks::getUrl()),
            CreateAction::make()->icon('heroicon-o-plus')->size(Size::Small)->slideOver(),
        ];
    }
}
