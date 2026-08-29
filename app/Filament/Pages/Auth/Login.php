<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Concerns\DetectsTeamInvitation;
use App\Enums\SocialiteProvider;
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
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Locked;

final class Login extends \Filament\Auth\Pages\Login
{
    use DetectsTeamInvitation;

    #[Locked]
    public ?string $authMethod = null;

    #[Locked]
    public bool $passkeyUserHasPassword = false;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Html::make(fn (): string => $this->getInvitationContentHtml()),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
                $this->getFormContentComponent(),
                Html::make(fn (): string => $this->getDiscoveryHintHtml()),
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

    private function getDiscoveryHintHtml(): string
    {
        if ($this->authMethod === 'passkey' && $this->passkeyUserHasPassword) {
            return Blade::render(<<<'BLADE'
                <p class="mt-3 text-center text-sm">
                    <button type="button" wire:click="usePassword" class="text-primary-600 underline-offset-2 hover:underline dark:text-primary-400">
                        {{ __('auth.login.use_password') }}
                    </button>
                </p>
            BLADE);
        }

        if (str_starts_with((string) $this->authMethod, 'social:')) {
            $provider = ucfirst(mb_substr((string) $this->authMethod, 7));

            return Blade::render(
                '<p class="mt-3 text-center text-sm text-gray-500 dark:text-gray-400">{{ __(\'auth.login.social_hint\', [\'provider\' => $provider]) }}</p>',
                ['provider' => $provider],
            );
        }

        return '';
    }

    protected function discover(): void
    {
        $email = mb_strtolower(trim((string) ($this->form->getState()['email'] ?? '')));

        $ipKey = 'login-discover-ip:'.request()->ip();

        if (RateLimiter::tooManyAttempts($ipKey, 20)) {
            throw ValidationException::withMessages([
                'data.email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($ipKey)]),
            ]);
        }

        $emailKey = 'login-discover:'.$email.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($emailKey, 5)) {
            throw ValidationException::withMessages([
                'data.email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($emailKey)]),
            ]);
        }

        RateLimiter::hit($ipKey, 60);
        RateLimiter::hit($emailKey, 60);

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

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

        $provider = $this->resolveOfferedProvider($user);

        $this->authMethod = $provider === null
            ? 'password'
            : 'social:'.$provider;
    }

    protected function resolveOfferedProvider(User $user): ?string
    {
        return $user->socialAccounts()
            ->pluck('provider_name')
            ->first(fn (?string $providerName): bool => $this->isProviderOffered($providerName));
    }

    protected function isProviderOffered(?string $providerName): bool
    {
        $provider = SocialiteProvider::tryFrom((string) $providerName);

        if ($provider === null) {
            return false;
        }

        return match ($provider) {
            SocialiteProvider::GOOGLE => true,
            SocialiteProvider::MICROSOFT => filled(config('services.microsoft.client_id')),
        };
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
