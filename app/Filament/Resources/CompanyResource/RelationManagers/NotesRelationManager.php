<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Filament\Resources\NoteResource\Forms\NoteForm;
use Filament\Actions\ActionGroup;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-document-text';

    public function form(Schema $schema): Schema
    {
        return NoteForm::get($schema, ['companies']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('people.name')
                    ->label(__('filament/resources/company.relation_managers.notes.fields.people.label'))
                    ->badge()
                    ->color('primary')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()->icon('heroicon-o-plus')->size(Size::Small),
                AttachAction::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DetachAction::make(),
                    DeleteAction::make(),
                ]),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
