<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Actions\CreateTaskAction;
use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Concerns\HasViewSwitcher;
use App\Filament\Concerns\KeepsOpenTaskInUrl;
use App\Filament\Exports\TaskExporter;
use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Size;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Override;
use Relaticle\ImportWizard\Filament\Pages\ImportTasks;

final class ManageTasks extends ManageRecords
{
    use HasCustomFieldColumns;
    use HasViewSwitcher;
    use KeepsOpenTaskInUrl;

    protected static string $resource = TaskResource::class;

    #[Override]
    public function mount(): void
    {
        parent::mount();

        if ($this->defaultTableAction !== EditAction::getDefaultName()) {
            return;
        }

        if (! is_string($this->defaultTableActionRecord)) {
            return;
        }

        $task = Task::query()->find($this->defaultTableActionRecord);

        // A link to edit a task (a notification, the home page) still opens it for a member who may only view it.
        if ($task instanceof Task && Gate::denies('update', $task)) {
            $this->defaultTableAction = ViewAction::getDefaultName();
        }
    }

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
