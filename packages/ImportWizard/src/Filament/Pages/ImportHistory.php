<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;

final class ImportHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'import-wizard-new::filament.pages.import-history';

    /**
     * How long an import may sit untouched before the recovery action appears. Comfortably
     * longer than the job's own timeout plus its retries, so a run that is merely slow is
     * never offered up as stalled.
     */
    private const int STALLED_AFTER_MINUTES = 30;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Import History';

    protected static ?string $title = 'Import History';

    protected static ?int $navigationSort = 100;

    protected static bool $shouldRegisterNavigation = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Import::query()
                    ->forTeam((string) filament()->getTenant()?->getKey())
                    ->whereIn('status', [ImportStatus::Completed, ImportStatus::Failed, ImportStatus::Importing])
                    ->latest()
            )
            ->columns([
                TextColumn::make('entity_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (ImportEntityType $state): string => $state->label())
                    ->icon(fn (ImportEntityType $state): string => $state->icon()),

                TextColumn::make('file_name')
                    ->label('File')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ImportStatus $state): string => match ($state) {
                        ImportStatus::Completed => 'success',
                        ImportStatus::Failed => 'danger',
                        ImportStatus::Importing => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('total_rows')
                    ->label('Total')
                    ->numeric(),

                TextColumn::make('created_rows')
                    ->label('Created')
                    ->numeric()
                    ->color('success'),

                TextColumn::make('updated_rows')
                    ->label('Updated')
                    ->numeric()
                    ->color('info'),

                TextColumn::make('skipped_rows')
                    ->label('Skipped')
                    ->numeric()
                    ->color('gray'),

                TextColumn::make('failed_rows')
                    ->label('Failed')
                    ->numeric()
                    ->color('danger'),

                TextColumn::make('user.name')
                    ->label('User'),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->options(ImportEntityType::class),

                SelectFilter::make('status')
                    ->options([
                        ImportStatus::Completed->value => 'Completed',
                        ImportStatus::Failed->value => 'Failed',
                        ImportStatus::Importing->value => 'Importing',
                    ]),
            ])
            ->actions([
                Action::make('downloadFailedRows')
                    ->label('Failed Rows')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('danger')
                    ->url(fn (Import $record): string => URL::temporarySignedRoute(
                        'import-history.failed-rows.download',
                        now()->addHour(),
                        ['import' => $record],
                    ), shouldOpenInNewTab: true)
                    ->visible(fn (Import $record): bool => $record->failedRows()->exists()),

                // A worker that dies between claiming the import and finishing it leaves the
                // row on "importing" with no way out: the wizard refuses to re-dispatch a
                // record in that state, so the user is stuck with a spinner and no recourse.
                // Only offered once the run is old enough that it cannot still be working.
                Action::make('markFailed')
                    ->label('Mark as failed')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This import stopped responding. Marking it failed lets you start a new one from the same file.')
                    ->action(fn (Import $record) => $record->update(['status' => ImportStatus::Failed]))
                    ->visible(fn (Import $record): bool => $record->status === ImportStatus::Importing
                        && $record->updated_at?->lt(now()->subMinutes(self::STALLED_AFTER_MINUTES)) === true),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('10s');
    }
}
