<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Support\Enums\Size;

final class Login extends \Filament\Auth\Pages\Login
{
    protected function getAuthenticateFormAction(): Action
    {
        return Action::make('authenticate')
            ->size(Size::Medium)
            ->label(__('filament-panels::auth/pages/login.form.actions.authenticate.label'))
            ->submit('authenticate');
    }
}
