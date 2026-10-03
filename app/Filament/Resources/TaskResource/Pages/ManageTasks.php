<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Actions\CreateTaskAction;
use App\Filament\Concerns\HasBoardViewSwitcher;
use App\Filament\Exports\TaskExporter;
use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Asmit\ResizedColumn\HasResizableColumn;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Size;
use Livewire\Attributes\On;
use Override;
use Relaticle\CustomFields\Concerns\InteractsWithCustomFields;
use Relaticle\ImportWizard\Filament\Pages\ImportTasks;

final class ManageTasks extends ManageRecords
{
    use HasBoardViewSwitcher;
    use HasResizableColumn;
    use InteractsWithCustomFields;

    protected static string $resource = TaskResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('import')
                    ->label(__('filament/resources/task.pages.list.actions.import.label'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->url(ImportTasks::getUrl())
                    ->visible(ImportTasks::canAccess(...)),
                ExportAction::make()->exporter(TaskExporter::class)->authorize('exportAny', Task::class),
            ])
                ->icon('heroicon-o-arrows-up-down')
                ->color('gray')
                ->button()
                ->label(__('filament/resources/task.pages.list.actions.import_export.label'))
                ->size(Size::Small),
            CreateTaskAction::make()->icon('heroicon-o-plus')->size(Size::Small)->slideOver(),
        ];
    }

    #[On('ai-write-completed')]
    public function refreshOnAiWrite(): void
    {
        // Filament table auto-refreshes on Livewire re-render
    }
}
