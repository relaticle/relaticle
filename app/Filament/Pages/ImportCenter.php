<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\HasImportEntities;
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
use Livewire\Attributes\Url;
use Override;
use UnitEnum;

final class ImportCenter extends Page implements HasTable
{
    use HasImportEntities;
    use InteractsWithTable;

    protected string $view = 'filament.pages.import-center';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Import Center';

    protected static string|null|UnitEnum $navigationGroup = 'Data Management';

    protected static ?int $navigationSort = 2;

    #[Url(as: 'tab')]
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

    public function table(Table $table): Table
    {
        $team = Filament::getTenant();

        return $table
            ->query(
                Import::query()
                    ->where('team_id', $team?->getKey())
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
                    ->state(fn (Import $record): int => $record->completed_at !== null
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
                    ->visible(fn (Import $record): bool => $record->completed_at !== null && $record->getFailedRowsCount() > 0),
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
     * Filament imports use job batches with a `finally` callback that sets `completed_at`.
     * The status is determined solely by the import record state - no payload parsing needed.
     *
     * Statuses:
     * - "Completed": Batch finished (`completed_at` set by Filament's finally callback)
     * - "Processing": Import is actively being processed (some rows done, not complete)
     * - "Pending": Waiting to start (no rows processed yet)
     */
    private function getImportStatus(Import $import): string
    {
        // Filament sets completed_at when the batch finishes (success or failure)
        if ($import->completed_at !== null) {
            return 'Completed';
        }

        // No rows processed yet - waiting for queue worker
        if ($import->processed_rows === 0) {
            return 'Pending';
        }

        // Some rows processed but not finished
        return 'Processing';
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }
}
