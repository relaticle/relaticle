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

                TextColumn::make('user.name')
                    ->label('User'),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->since()
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
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('10s');
    }
}
