<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Actions\ImportExportActionGroup;
use App\Filament\Exports\NoteExporter;
use App\Models\Note;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Support\Enums\Size;
use Relaticle\ImportWizard\Filament\Pages\ImportNotes;

trait HasNoteHeaderActions
{
    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ImportExportActionGroup::make([
                Action::make('import')
                    ->label(__('filament/resources/note.pages.list.actions.import.label'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->url(ImportNotes::getUrl())
                    ->visible(ImportNotes::canAccess(...)),
                ExportAction::make()->exporter(NoteExporter::class)->authorize('exportAny', Note::class),
            ])->label(__('filament/resources/note.pages.list.actions.import_export.label')),
            CreateAction::make()->icon('heroicon-o-plus')->size(Size::Small),
        ];
    }
}
