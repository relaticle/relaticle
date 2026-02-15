<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use App\Enums\CreationSource;
use App\Models\Note;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\NoteResource\Pages\CreateNote;
use Relaticle\SystemAdmin\Filament\Resources\NoteResource\Pages\EditNote;
use Relaticle\SystemAdmin\Filament\Resources\NoteResource\Pages\ListNotes;
use Relaticle\SystemAdmin\Filament\Resources\NoteResource\Pages\ViewNote;

final class NoteResource extends Resource
{
    protected static ?string $model = Note::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Note';

    protected static ?string $pluralModelLabel = 'Notes';

    protected static ?string $slug = 'notes';

    public static function getNavigationBadge(): ?string
    {
        return self::getModel()::query()->count() > 0 ? (string) self::getModel()::query()->count() : null;
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('team_id')
                    ->relationship('team', 'name')
                    ->searchable()
                    ->required(),
                TextInput::make('title')
                    ->maxLength(255),
                Select::make('creation_source')
                    ->options(CreationSource::class)
                    ->default(CreationSource::WEB),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('creation_source')
                    ->badge()
                    ->color(fn (CreationSource $state): string => match ($state) {
                        CreationSource::WEB => 'info',
                        CreationSource::SYSTEM => 'warning',
                        CreationSource::IMPORT => 'success',
                    })
                    ->label('Source')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('team')
                    ->relationship('team', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('creation_source')
                    ->label('Source')
                    ->options(CreationSource::class)
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    #[Override]
    public static function getRelations(): array
    {
        return [];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListNotes::route('/'),
            'create' => CreateNote::route('/create'),
            'view' => ViewNote::route('/{record}'),
            'edit' => EditNote::route('/{record}/edit'),
        ];
    }
}
