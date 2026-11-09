<?php

declare(strict_types=1);

namespace App\Filament\Resources\PeopleResource\Pages;

use App\Filament\Actions\ImportExportActionGroup;
use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Exports\PeopleExporter;
use App\Filament\Resources\PeopleResource;
use App\Models\People;
use Asmit\ResizedColumn\HasResizableColumn;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Size;
use Livewire\Attributes\On;
use Override;
use Relaticle\ImportWizard\Filament\Pages\ImportPeople;

final class ListPeople extends ListRecords
{
    use HasCustomFieldColumns;
    use HasResizableColumn;

    protected static string $resource = PeopleResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ImportExportActionGroup::make([
                Action::make('import')
                    ->label(__('filament/resources/person.pages.list.actions.import.label'))
                    ->icon('lucide-upload')
                    ->url(ImportPeople::getUrl())
                    ->visible(ImportPeople::canAccess(...)),
                ExportAction::make()->exporter(PeopleExporter::class)->authorize('exportAny', People::class),
            ])->label(__('filament/resources/person.pages.list.actions.import_export.label')),
            CreateAction::make()->icon('lucide-plus')->size(Size::Small),
        ];
    }

    #[On('ai-write-completed')]
    public function refreshOnAiWrite(): void
    {
        // Filament table auto-refreshes on Livewire re-render
    }
}
