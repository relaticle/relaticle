<?php

declare(strict_types=1);

namespace App\Filament\Resources\NoteResource\Forms;

use App\Enums\CustomFields\NoteField;
use App\Filament\Components\Forms\LinkedRecordsSelect;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Relaticle\CustomFields\Facades\CustomFields;

final class NoteForm
{
    /**
     * @param  array<string>  $excludeFields
     *
     * @throws \Exception
     */
    public static function get(Schema $schema, array $excludeFields = []): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label(__('filament/resources/note.fields.title.label'))
                    ->rules(['max:255'])
                    ->required(),
                LinkedRecordsSelect::make('relations')->withoutRelations($excludeFields),
                CustomFields::form()->only([NoteField::BODY->value])->build(),
                CustomFields::form()->except([NoteField::BODY->value])->build(),
            ])
            ->columns(1);
    }
}
