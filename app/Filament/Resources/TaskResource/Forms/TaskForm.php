<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Forms;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Relaticle\CustomFields\Facades\CustomFields;

final class TaskForm
{
    /**
     * @param  array<string>  $excludeFields
     *
     * @throws \Exception
     */
    public static function get(Schema $schema, array $excludeFields = []): Schema
    {
        $components = [
            TextInput::make('title')
                ->required()
                ->columnSpanFull(),
        ];

        if (! in_array('companies', $excludeFields)) {
            $components[] = Select::make('companies')
                ->label(__('filament/resources/task.fields.companies.label'))
                ->multiple()
                ->relationship('companies', 'name')
                ->columnSpanFull();
        }

        if (! in_array('people', $excludeFields)) {
            $components[] = Select::make('people')
                ->label(__('filament/resources/task.fields.people.label'))
                ->multiple()
                ->relationship('people', 'name')
                ->nullable();
        }

        $components[] = Select::make('assignees')
            ->label(__('filament/resources/task.fields.assignees.label'))
            ->multiple()
            ->relationship('assignees', 'name')
            ->nullable();

        $components[] = CustomFields::form()->except($excludeFields)->build()->columnSpanFull()->columns(1);

        return $schema
            ->components($components)
            ->columns(2);
    }
}
