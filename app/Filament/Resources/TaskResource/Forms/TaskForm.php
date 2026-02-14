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
                ->label('Companies')
                ->multiple()
                ->relationship('companies', 'name')
                ->columnSpanFull();
        }

        if (! in_array('people', $excludeFields)) {
            $components[] = Select::make('people')
                ->label('People')
                ->multiple()
                ->relationship('people', 'name')
                ->nullable();
        }

        $components[] = Select::make('assignees')
            ->label('Assignees')
            ->multiple()
            ->relationship('assignees', 'name')
            ->nullable();

        $components[] = CustomFields::form()->except($excludeFields)->build()->columnSpanFull()->columns(1);

        return $schema
            ->components($components)
            ->columns(2);
    }
}
