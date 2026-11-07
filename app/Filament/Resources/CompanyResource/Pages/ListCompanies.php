<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Actions\ImportExportActionGroup;
use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Exports\CompanyExporter;
use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use Asmit\ResizedColumn\HasResizableColumn;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Size;
use Livewire\Attributes\On;
use Override;
use Relaticle\ImportWizard\Filament\Pages\ImportCompanies;

final class ListCompanies extends ListRecords
{
    use HasCustomFieldColumns;
    use HasResizableColumn;

    /** @var class-string<CompanyResource> */
    protected static string $resource = CompanyResource::class;

    /**
     * Get the actions available on the resource index header.
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ImportExportActionGroup::make([
                Action::make('import')
                    ->label(__('filament/resources/company.pages.list.actions.import.label'))
                    ->icon('lucide-upload')
                    ->url(ImportCompanies::getUrl())
                    ->visible(ImportCompanies::canAccess(...)),
                ExportAction::make()->exporter(CompanyExporter::class)->authorize('exportAny', Company::class),
            ])->label(__('filament/resources/company.pages.list.actions.import_export.label')),
            CreateAction::make()->icon('lucide-plus')->size(Size::Small),
        ];
    }

    #[On('ai-write-completed')]
    public function refreshOnAiWrite(): void
    {
        // Filament table auto-refreshes on Livewire re-render
    }
}
