<?php

declare(strict_types=1);

namespace App\Filament\Support\InlineField;

use App\Filament\Components\Forms\RecordSelect;
use App\Filament\Components\Forms\WorkspaceMemberSelect;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;

final class NativeFormField
{
    public static function make(string $code): Field
    {
        return match ($code) {
            'name' => TextInput::make('name')
                ->required()
                ->maxLength(255),
            'account_owner_id' => WorkspaceMemberSelect::make('account_owner_id')
                ->relationship('accountOwner', 'name')
                ->label(__('filament/resources/company.fields.account_owner_id.label'))
                ->nullable(),
            'company_id' => RecordSelect::make('company_id')
                ->relationship('company', 'name')
                ->searchable()
                ->preload()
                ->nullable(),
            'contact_id' => RecordSelect::make('contact_id')
                ->relationship('contact', 'name')
                ->searchable()
                ->preload()
                ->nullable(),
            default => abort(404),
        };
    }
}
