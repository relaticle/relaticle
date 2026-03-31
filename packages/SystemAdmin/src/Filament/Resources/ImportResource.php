<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Override;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\SystemAdmin\Filament\Resources\ImportResource\Pages\ListImports;
use Relaticle\SystemAdmin\Filament\Resources\ImportResource\Pages\ViewImport;
use Relaticle\SystemAdmin\Filament\Resources\ImportResource\RelationManagers\FailedRowsRelationManager;

final class ImportResource extends Resource
{
    protected static ?string $model = Import::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Import';

    protected static ?string $pluralModelLabel = 'Imports';

    protected static ?string $slug = 'imports';

    public static function getNavigationBadge(): ?string
    {
        $count = self::getModel()::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('file_name')
                    ->label('File Name'),
                TextEntry::make('entity_type')
                    ->badge(),
                TextEntry::make('status')
                    ->badge()
                    ->color(self::statusColor(...)),
                TextEntry::make('team.name')
                    ->label('Team'),
                TextEntry::make('user.name')
                    ->label('User'),
                TextEntry::make('total_rows'),
                TextEntry::make('created_rows'),
                TextEntry::make('updated_rows'),
                TextEntry::make('skipped_rows'),
                TextEntry::make('failed_rows'),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('completed_at')
                    ->dateTime(),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('file_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('entity_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(self::statusColor(...))
                    ->sortable(),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('total_rows')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('created_rows')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('failed_rows')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->options(ImportEntityType::class)
                    ->multiple(),
                SelectFilter::make('status')
                    ->options(ImportStatus::class)
                    ->multiple(),
                SelectFilter::make('team')
                    ->relationship('team', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            FailedRowsRelationManager::class,
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListImports::route('/'),
            'view' => ViewImport::route('/{record}'),
        ];
    }

    private static function statusColor(ImportStatus $state): string
    {
        return match ($state) {
            ImportStatus::Completed => 'success',
            ImportStatus::Failed => 'danger',
            ImportStatus::Importing => 'warning',
            default => 'gray',
        };
    }
}
