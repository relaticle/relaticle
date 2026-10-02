<?php

declare(strict_types=1);

namespace App\Filament\Resources\PeopleResource\RelationManagers;

use App\Filament\Components\Tables\RelatedRecordColumns;
use App\Filament\Concerns\CountsRelatedRecords;
use App\Filament\Resources\NoteResource\Forms\NoteForm;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Tables\Table;

final class NotesRelationManager extends RelationManager
{
    use CountsRelatedRecords;

    protected static string $relationship = 'notes';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-document-text';

    public function form(Schema $schema): Schema
    {
        return NoteForm::get($schema, ['people']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns(RelatedRecordColumns::notes())
            ->headerActions([
                CreateAction::make()->icon('heroicon-o-plus')->size(Size::Small),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
