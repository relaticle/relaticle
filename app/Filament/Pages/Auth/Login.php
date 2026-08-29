<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Concerns\DetectsTeamInvitation;
use App\Features\SocialAuth;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

final class Login extends \Filament\Auth\Pages\Login
{
    use DetectsTeamInvitation;

    public ?string $authMethod = null;

    public bool $passkeyUserHasPassword = false;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Html::make(fn (): string => $this->getInvitationContentHtml()),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
                $this->getFormContentComponent(),
                $this->getMultiFactorChallengeFormContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
            ]);
    }

    public function authenticate(): ?LoginResponse
    {
        if ($this->authMethod === 'passkey') {
            $this->dispatch('passkey-login');

            return null;
        }

        if (str_starts_with((string) $this->authMethod, 'social:')) {
            return null;
        }

        if ($this->authMethod === null) {
            $this->discover();

            return null;
        }

        return parent::authenticate();
    }

    public function usePassword(): void
    {
        $this->authMethod = 'password';
    }

    protected function discover(): void
    {
        $email = mb_strtolower(trim((string) ($this->form->getState()['email'] ?? '')));

        $key = 'login-discover:'.$email.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'data.email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 60);

        $user = User::query()->where('email', $email)->first();

        if ($user?->hasPasskey()) {
            $this->authMethod = 'passkey';
            $this->passkeyUserHasPassword = $user->hasPassword();
            $this->dispatch('passkey-login');

            return;
        }

        if ($user === null || $user->hasPassword()) {
            $this->authMethod = 'password';

            return;
        }

        if (! Feature::active(SocialAuth::class)) {
            $this->authMethod = 'password';

            return;
        }

        $provider = $user->socialAccounts()->value('provider_name');
        $this->authMethod = 'social:'.$provider;
    }

    protected function getAuthenticateFormAction(): Action
    {
        return Action::make('authenticate')
            ->size(Size::Medium)
            ->label(fn (): string => $this->authMethod === 'password'
                ? __('filament-panels::auth/pages/login.form.actions.authenticate.label')
                : __('auth.login.continue'))
            ->submit('authenticate');
    }

    protected function getEmailFormComponent(): TextInput
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/login.form.email.label'))
            ->email()
            ->required()
            ->autocomplete('username webauthn')
            ->autofocus()
            ->live(onBlur: true)
            ->afterStateUpdated(function (): void {
                $this->authMethod = null;
                $this->passkeyUserHasPassword = false;
            });
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->visible(fn (): bool => $this->authMethod === 'password');
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()
            ->visible(fn (): bool => $this->authMethod === 'password');
    }
}
