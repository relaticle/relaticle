<?php

declare(strict_types=1);

namespace App\Filament\Resources\PeopleResource\RelationManagers;

use App\Filament\Resources\TaskResource\Forms\TaskForm;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Relaticle\CustomFields\Facades\CustomFields;

final class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clipboard-document-check';

    public function form(Schema $schema): Schema
    {
        return TaskForm::get($schema, ['people']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title'),
                ...CustomFields::table()->forModel($table->getModel())->columns(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()->icon('heroicon-o-plus')->size(Size::Small),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
