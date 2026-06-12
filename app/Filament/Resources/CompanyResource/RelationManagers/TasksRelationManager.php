<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Filament\Resources\TaskResource\Forms\TaskForm;
use Filament\Actions\ActionGroup;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-check-circle';

    public function form(Schema $schema): Schema
    {
        return TaskForm::get($schema, ['companies']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('assignees.name')
                    ->label(__('filament/resources/company.relation_managers.tasks.fields.assignees.label'))
                    ->badge()
                    ->color('primary')
                    ->searchable(),
                TextColumn::make('people.name')
                    ->label(__('filament/resources/company.relation_managers.tasks.fields.people.label'))
                    ->badge()
                    ->color('primary')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label(__('filament/resources/company.relation_managers.tasks.fields.created_at.label'))
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
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
