<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Exports\CompanyExporter;
use App\Filament\Resources\CompanyResource;
use Asmit\ResizedColumn\HasResizableColumn;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Size;
use Livewire\Attributes\On;
use Override;
use Relaticle\CustomFields\Concerns\InteractsWithCustomFields;
use Relaticle\ImportWizard\Filament\Pages\ImportCompanies;

final class ListCompanies extends ListRecords
{
    use HasResizableColumn;
    use InteractsWithCustomFields;

    /** @var class-string<CompanyResource> */
    protected static string $resource = CompanyResource::class;

    /**
     * Get the actions available on the resource index header.
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('import')
                    ->label(__('filament/resources/company.pages.list.actions.import.label'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->url(ImportCompanies::getUrl()),
                ExportAction::make()->exporter(CompanyExporter::class),
            ])
                ->icon('heroicon-o-arrows-up-down')
                ->color('gray')
                ->button()
                ->label(__('filament/resources/company.pages.list.actions.import_export.label'))
                ->size(Size::Small),
            CreateAction::make()->icon('heroicon-o-plus')->size(Size::Small),
        ];
    }

    #[On('ai-write-completed')]
    public function refreshOnAiWrite(): void
    {
        // Filament table auto-refreshes on Livewire re-render
    }
}
