<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource\Forms;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Relaticle\CustomFields\Facades\CustomFields;

final class OpportunityForm
{
    public static function get(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->placeholder('Enter opportunity title')
                    ->columnSpanFull(),
                Select::make('company_id')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload()
                    ->columnSpan(2),
                Select::make('contact_id')
                    ->relationship('contact', 'name')
                    ->searchable()
                    ->preload()
                    ->columnSpan(2),
                CustomFields::form()->forSchema($schema)->build()->columnSpanFull()->columns(1),
            ])
            ->columns(4);
    }
}
