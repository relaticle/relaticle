<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Support\EmailAddress;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Password;

final class RequestPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
    public function getMaxWidth(): Width
    {
        return Width::Medium;
    }

    public function hasLogo(): bool
    {
        return false;
    }

    protected function getEmailFormComponent(): TextInput
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/password-reset/request-password-reset.form.email.label'))
            ->hiddenLabel()
            ->placeholder(__('auth.login.email_placeholder'))
            ->email()
            ->required()
            ->autocomplete()
            ->autofocus()
            ->dehydrateStateUsing(fn (?string $state): string => EmailAddress::canonicalize((string) $state));
    }

    /**
     * An unknown email and a broker-throttled retry both answer exactly like
     * success, so this page cannot be used to probe which accounts exist.
     */
    protected function getFailureNotification(string $status): ?Notification
    {
        $this->form->fill();

        return $this->getSentNotification(Password::RESET_LINK_SENT);
    }

    protected function getRequestFormAction(): Action
    {
        return parent::getRequestFormAction()
            ->size(Size::Medium);
    }
}
