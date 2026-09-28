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
                ->maxLength(255)
                ->extraInputAttributes([
                    'x-on:keydown.enter.prevent' => 'Alpine.$data($event.target.closest(\'.fi-inline-field-editor\')).saveFromEnter()',
                ]),
            'account_owner_id' => WorkspaceMemberSelect::make('account_owner_id')
                ->relationship('accountOwner', 'name')
                ->label(__('filament/resources/company.fields.account_owner_id.label'))
                ->searchPrompt(__('filament/inline-edit.search_records'))
                ->nullable()
                ->extraAttributes(['class' => 'fi-inline-overlay-select']),
            'company_id' => RecordSelect::make('company_id')
                ->relationship('company', 'name')
                ->searchable()
                ->preload()
                ->searchPrompt(__('filament/inline-edit.search_records'))
                ->nullable()
                ->extraAttributes(['class' => 'fi-inline-overlay-select']),
            'contact_id' => RecordSelect::make('contact_id')
                ->relationship('contact', 'name')
                ->searchable()
                ->preload()
                ->searchPrompt(__('filament/inline-edit.search_records'))
                ->nullable()
                ->extraAttributes(['class' => 'fi-inline-overlay-select']),
            default => abort(404),
        };
    }
}
