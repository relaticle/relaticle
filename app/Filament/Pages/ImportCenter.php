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
use Illuminate\Support\Facades\DB;
use Override;
use UnitEnum;

final class ImportCenter extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.import-center';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Import Center';

    protected static string|null|UnitEnum $navigationGroup = 'Data Management';

    protected static ?int $navigationSort = 2;

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
                    ->state(fn (Import $record): int => $this->isImportCompleted($record)
                        ? $record->getFailedRowsCount()
                        : 0
                    )
                    ->numeric()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (Import $record): string => $this->getImportStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Completed' => 'success',
                        'Processing' => 'warning',
                        'Pending' => 'info',
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
                    ->visible(fn (Import $record): bool => $this->isImportCompleted($record) && $record->getFailedRowsCount() > 0),
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

    /**
     * Determine the display status for an import.
     *
     * Possible statuses:
     * - "Completed": Import finished successfully (may have row-level validation failures)
     * - "Failed": Import job failed entirely (exception thrown, stuck, or no progress)
     * - "Processing": Import is actively being processed
     * - "Pending": Import was created but processing hasn't started
     */
    private function getImportStatus(Import $import): string
    {
        // Check if fully completed (batch finished callback was called)
        if ($import->completed_at !== null) {
            return 'Completed';
        }

        // Check if all rows have been processed (completed but completed_at not set yet)
        if ($import->total_rows > 0 && $import->processed_rows >= $import->total_rows) {
            return 'Completed';
        }

        // Check if import job failed entirely
        if ($this->hasImportJobFailed($import)) {
            return 'Failed';
        }

        // Check if import is stuck (no progress for too long)
        if ($this->isImportStuck($import)) {
            return 'Failed';
        }

        // Import hasn't started processing yet (queue worker may not be running)
        if ($import->processed_rows === 0) {
            return 'Pending';
        }

        return 'Processing';
    }

    /**
     * Check if an import has completed (either successfully or with the batch finishing).
     */
    private function isImportCompleted(Import $import): bool
    {
        if ($import->completed_at !== null) {
            return true;
        }

        return $import->total_rows > 0 && $import->processed_rows >= $import->total_rows;
    }

    /**
     * Check if the import job has failed by looking at the failed_jobs table.
     */
    private function hasImportJobFailed(Import $import): bool
    {
        return DB::table('failed_jobs')
            ->where('payload', 'like', '%'.$import->getKey().'%')
            ->exists();
    }

    /**
     * Check if an import appears to be stuck (started processing but no progress).
     *
     * An import is considered stuck only if:
     * - It started processing (processed_rows > 0)
     * - It has been more than 10 minutes since last update
     * - It still has rows to process
     *
     * Note: We do NOT mark as stuck if processing never started - that's "Pending",
     * not "Failed". The queue worker might just not be running.
     */
    private function isImportStuck(Import $import): bool
    {
        // If completed, not stuck
        if ($import->completed_at !== null) {
            return false;
        }

        // If all rows processed, not stuck
        if ($import->total_rows > 0 && $import->processed_rows >= $import->total_rows) {
            return false;
        }

        // Only consider stuck if processing actually started but then stopped
        if ($import->processed_rows === 0) {
            return false;
        }

        // Check if updated recently (within last 10 minutes)
        $stuckThreshold = now()->subMinutes(10);

        // If updated_at is older than threshold and we have unprocessed rows, it's stuck
        return $import->updated_at < $stuckThreshold && $import->total_rows > 0;
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }
}
