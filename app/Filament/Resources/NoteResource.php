<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Filament\Components\Tables\EmptyListDescription;
use App\Filament\Components\Tables\FilteredEmptyState;
use App\Filament\Components\Tables\LinkedRecordsColumn;
use App\Filament\Exports\NoteExporter;
use App\Filament\Resources\NoteResource\Forms\NoteForm;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\NoteResource\Pages\NotesCards;
use App\Filament\Tables\BulkBar;
use App\Filament\Tables\RowActions;
use App\Models\Note;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;

final class NoteResource extends Resource
{
    protected static ?string $model = Note::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('filament/resources/note.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/note.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament/resources/note.navigation_label');
    }

    public static function getNavigationIcon(): string
    {
        return CrmEntity::Note->icon();
    }

    public static function form(Schema $schema): Schema
    {
        return NoteForm::get($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateDescription(EmptyListDescription::unlessFiltered(__('filament/resources/note.empty_state.description')))
            ->emptyStateHeading(FilteredEmptyState::heading())
            ->emptyStateActions([FilteredEmptyState::clearAction()])
            ->columns(self::columns())
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('creation_source')
                    ->label(__('filament/resources/note.filters.creation_source.label'))
                    ->options(CreationSource::class)
                    ->multiple(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                RowActions::withoutMenu([
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkBar::make([
                    ExportBulkAction::make()
                        ->authorize('exportAny', Note::class)
                        ->exporter(NoteExporter::class),
                    RestoreBulkAction::make(),
                ], more: [
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<int, Column>
     */
    private static function columns(): array
    {
        return [
            TextColumn::make('title'),
            LinkedRecordsColumn::make('relations')
                ->toggleable(),
            TextColumn::make('creator.name')
                ->label(__('filament/resources/note.fields.creator.label'))
                ->sortable()
                ->toggleable()
                ->visibleFrom('sm')
                ->getStateUsing(fn (Note $record): string => $record->created_by),
            TextColumn::make('deleted_at')
                ->dateTime()
                ->sortable()
                ->toggleable()
                ->toggledHiddenByDefault(),
            TextColumn::make('created_at')
                ->label(__('filament/resources/note.fields.created_at.label'))
                ->dateTime()
                ->sortable()
                ->visibleFrom('sm')
                ->toggleable(),
            TextColumn::make('updated_at')
                ->label(__('filament/resources/note.fields.updated_at.label'))
                ->dateTime()
                ->sortable()
                ->toggleable()
                ->toggledHiddenByDefault(),
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => NotesCards::route('/'),
            'list' => ManageNotes::route('/list'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['companies.media', 'people', 'opportunities', 'customFieldValues.customField.options'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
