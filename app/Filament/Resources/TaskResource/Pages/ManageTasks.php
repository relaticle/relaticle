<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Actions\Task\NotifyTaskAssignees;
use App\Filament\Concerns\HasBoardViewSwitcher;
use App\Filament\Exports\TaskExporter;
use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Asmit\ResizedColumn\HasResizableColumn;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
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
        /** @var array<int, string> $submittedAssigneeIds */
        $submittedAssigneeIds = [];
        $createTaskAction = CreateAction::make()
            ->icon('heroicon-o-plus')
            ->size(Size::Small)
            ->slideOver()
            ->before(function () use (&$createTaskAction, &$submittedAssigneeIds): void {
                $submittedAssignees = $createTaskAction->getRawData()['assignees'] ?? [];

                if (! is_array($submittedAssignees)) {
                    $submittedAssignees = [];
                }

                $submittedAssigneeIds = array_values(array_filter($submittedAssignees, is_string(...)));
            })
            ->after(function (Task $record) use (&$submittedAssigneeIds): void {
                resolve(NotifyTaskAssignees::class)->execute($record, $submittedAssigneeIds);
            });

        return [
            ActionGroup::make([
                Action::make('import')
                    ->label(__('filament/resources/task.pages.list.actions.import.label'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->url(ImportTasks::getUrl()),
                ExportAction::make()->exporter(TaskExporter::class),
            ])
                ->icon('heroicon-o-arrows-up-down')
                ->color('gray')
                ->button()
                ->label(__('filament/resources/task.pages.list.actions.import_export.label'))
                ->size(Size::Small),
            $createTaskAction,
        ];
    }

    #[On('ai-write-completed')]
    public function refreshOnAiWrite(): void
    {
        // Filament table auto-refreshes on Livewire re-render
    }
}
