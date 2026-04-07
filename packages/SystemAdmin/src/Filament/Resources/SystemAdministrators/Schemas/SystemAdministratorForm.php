<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;

final class SystemAdministratorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Administrator Details')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        Select::make('role')
                            ->options(
                                collect(SystemAdministratorRole::cases())
                                    ->mapWithKeys(fn (SystemAdministratorRole $role): array => [
                                        $role->value => $role->getLabel(),
                                    ])
                            )
                            ->default(SystemAdministratorRole::SuperAdministrator->value)
                            ->required(),

                        DateTimePicker::make('email_verified_at')
                            ->label('Email Verified At')
                            ->displayFormat('M j, Y \a\t g:i A')
                            ->nullable(),

                        TextInput::make('password')
                            ->password()
                            ->dehydrateStateUsing(fn (?string $state): ?string => in_array($state, [null, '', '0'], true) ? null : Hash::make($state))
                            ->dehydrated(filled(...))
                            ->required(fn (Get $get): bool => ! $get('id'))
                            ->maxLength(255)
                            ->confirmed()
                            ->helperText(fn (Get $get): ?string => $get('id') ? 'Leave blank to keep current password' : null),

                        TextInput::make('password_confirmation')
                            ->password()
                            ->dehydrated(false)
                            ->required(fn (Get $get): bool => ! $get('id') && filled($get('password')))
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ]);
    }
}
