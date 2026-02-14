<?php

declare(strict_types=1);

namespace App\Filament\Resources\NoteResource\Forms;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Relaticle\CustomFields\Facades\CustomFields;

final class NoteForm
{
    /**
     * @param  array<string>  $excludeFields  Fields to exclude from the form.
     * @return Schema The modified form instance with the schema applied.
     *
     * @throws \Exception
     */
    public static function get(Schema $schema, array $excludeFields = []): Schema
    {
        $components = [
            TextInput::make('title')
                ->label('Title')
                ->rules(['max:255'])
                ->columnSpanFull()
                ->required(),
        ];

        if (! in_array('companies', $excludeFields)) {
            $components[] = Select::make('companies')
                ->label('Companies')
                ->multiple()
                ->relationship('companies', 'name');
        }

        if (! in_array('people', $excludeFields)) {
            $components[] = Select::make('people')
                ->label('People')
                ->multiple()
                ->relationship('people', 'name')
                ->nullable();
        }

        $components[] = CustomFields::form()->build()
            ->columnSpanFull()
            ->columns(1);

        return $schema
            ->components($components)
            ->columns(2);
    }
}
