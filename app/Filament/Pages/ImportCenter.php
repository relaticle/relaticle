<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\EnhancedImportAction;
use App\Filament\Imports\BaseImporter;
use App\Filament\Imports\CompanyImporter;
use App\Filament\Imports\NoteImporter;
use App\Filament\Imports\OpportunityImporter;
use App\Filament\Imports\PeopleImporter;
use App\Filament\Imports\TaskImporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\IconSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Override;
use UnitEnum;

final class ImportCenter extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.import-center';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Import Center';

    protected static string|null|UnitEnum $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 10;

    public string $activeTab = 'quick-import';

    #[Override]
    public function getTitle(): string
    {
        return 'Import Center';
    }

    #[Override]
    public function getSubheading(): string
    {
        return 'Import data from CSV or Excel files, or migrate from another CRM';
    }

    /**
     * @return array<string, array{label: string, icon: string, description: string, importer: class-string<BaseImporter>}>
     */
    public function getEntityTypes(): array
    {
        return [
            'companies' => [
                'label' => 'Companies',
                'icon' => 'heroicon-o-building-office-2',
                'description' => 'Import company records with addresses, phone numbers, and custom fields',
                'importer' => CompanyImporter::class,
            ],
            'people' => [
                'label' => 'People',
                'icon' => 'heroicon-o-users',
                'description' => 'Import contacts with their company associations and custom fields',
                'importer' => PeopleImporter::class,
            ],
            'opportunities' => [
                'label' => 'Opportunities',
                'icon' => 'heroicon-o-currency-dollar',
                'description' => 'Import deals and opportunities with values, stages, and dates',
                'importer' => OpportunityImporter::class,
            ],
            'tasks' => [
                'label' => 'Tasks',
                'icon' => 'heroicon-o-clipboard-document-check',
                'description' => 'Import tasks with priorities, statuses, and entity associations',
                'importer' => TaskImporter::class,
            ],
            'notes' => [
                'label' => 'Notes',
                'icon' => 'heroicon-o-document-text',
                'description' => 'Import notes linked to companies, people, or opportunities',
                'importer' => NoteImporter::class,
            ],
        ];
    }

    /**
     * Register import actions for each entity type.
     * These are defined as action methods so Filament properly registers them.
     */
    public function importCompaniesAction(): EnhancedImportAction
    {
        return $this->makeImportAction('companies');
    }

    public function importPeopleAction(): EnhancedImportAction
    {
        return $this->makeImportAction('people');
    }

    public function importOpportunitiesAction(): EnhancedImportAction
    {
        return $this->makeImportAction('opportunities');
    }

    public function importTasksAction(): EnhancedImportAction
    {
        return $this->makeImportAction('tasks');
    }

    public function importNotesAction(): EnhancedImportAction
    {
        return $this->makeImportAction('notes');
    }

    /**
     * Create an import action for a specific entity type.
     */
    private function makeImportAction(string $entityType): EnhancedImportAction
    {
        $config = $this->getEntityTypes()[$entityType];

        return EnhancedImportAction::make("import_{$entityType}")
            ->importer($config['importer'])
            ->label("Import {$config['label']}")
            ->modalHeading("Import {$config['label']}")
            ->color('primary');
    }

    /**
     * Get import action for a specific entity type.
     */
    public function getImportAction(string $entityType): EnhancedImportAction
    {
        $config = $this->getEntityTypes()[$entityType] ?? null;

        if ($config === null) {
            throw new \InvalidArgumentException("Unknown entity type: {$entityType}");
        }

        return EnhancedImportAction::make("import_{$entityType}")
            ->importer($config['importer'])
            ->label("Import {$config['label']}")
            ->modalHeading("Import {$config['label']}")
            ->color('primary');
    }

    /**
     * @return array<EnhancedImportAction>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        $team = Filament::getTenant();

        return $table
            ->query(
                Import::query()
                    ->where('team_id', $team?->getKey())
                    ->latest()
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('importer')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => $this->formatImporterName($state))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('file_name')
                    ->label('File')
                    ->limit(30)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('successful_rows')
                    ->label('Imported')
                    ->numeric()
                    ->color('success'),
                TextColumn::make('failed_rows_count')
                    ->label('Failed')
                    ->state(fn (Import $record): int => $record->getFailedRowsCount())
                    ->numeric()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (Import $record): string => $this->getImportStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Completed' => 'success',
                        'Processing' => 'warning',
                        'Failed' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->actions([
                Action::make('download_failed')
                    ->label('Download Failed Rows')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->iconSize(IconSize::Small)
                    ->color('danger')
                    ->url(fn (Import $record): string => route('filament.imports.failed-rows.download', [
                        'import' => $record,
                    ]))
                    ->visible(fn (Import $record): bool => $record->getFailedRowsCount() > 0),
            ])
            ->emptyStateHeading('No imports yet')
            ->emptyStateDescription('Import your first batch of data using the Quick Import section above.')
            ->emptyStateIcon('heroicon-o-arrow-up-tray')
            ->defaultSort('created_at', 'desc')
            ->poll('5s');
    }

    private function formatImporterName(string $importerClass): string
    {
        $className = class_basename($importerClass);

        return str($className)
            ->replace('Importer', '')
            ->headline()
            ->toString();
    }

    private function getImportStatus(Import $import): string
    {
        if ($import->completed_at !== null) {
            return 'Completed';
        }

        if ($import->processed_rows >= $import->total_rows) {
            return 'Completed';
        }

        return 'Processing';
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }
}
