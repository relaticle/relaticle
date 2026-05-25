<?php

declare(strict_types=1);

use Filament\Forms\Components\TextInput;

TextInput::make('name')
    ->label(__('filament/resources/company.fields.account_owner'));
