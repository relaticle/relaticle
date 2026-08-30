<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;

final class ResetPassword extends \Filament\Auth\Pages\PasswordReset\ResetPassword
{
    public function getMaxWidth(): Width
    {
        return Width::Medium;
    }

    public function hasLogo(): bool
    {
        return false;
    }

    protected function getPasswordFormComponent(): TextInput
    {
        $component = parent::getPasswordFormComponent();
        assert($component instanceof TextInput);

        return $component
            ->markAsRequired(false)
            ->helperText(__('auth.login.password_helper'));
    }

    protected function getPasswordConfirmationFormComponent(): TextInput
    {
        $component = parent::getPasswordConfirmationFormComponent();
        assert($component instanceof TextInput);

        return $component->markAsRequired(false);
    }

    public function getResetPasswordFormAction(): Action
    {
        return parent::getResetPasswordFormAction()
            ->size(Size::Medium);
    }
}
