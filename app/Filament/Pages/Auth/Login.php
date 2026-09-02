<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Concerns\DetectsTeamInvitation;
use App\Enums\SocialiteProvider;
use App\Features\SocialAuth;
use App\Models\User;
use App\Rules\RegistrableEmail;
use App\Support\EmailAddress;
use Filament\Actions\Action;
use Filament\Auth\Events\Registered;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Locked;
use SensitiveParameter;
use Spatie\Honeypot\Http\Livewire\Concerns\HoneypotData;
use Spatie\Honeypot\Http\Livewire\Concerns\UsesSpamProtection;

final class Login extends \Filament\Auth\Pages\Login
{
    use DetectsTeamInvitation;
    use UsesSpamProtection;

    #[Locked]
    public ?string $authMethod = null;

    #[Locked]
    public bool $passkeyUserHasPassword = false;

    #[Locked]
    public ?string $discoveredEmail = null;

    public HoneypotData $extraFields;

    public function mount(): void
    {
        $this->extraFields = new HoneypotData;

        parent::mount();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Html::make(fn (): string => $this->getInvitationContentHtml())
                    ->visible(fn (): bool => $this->getInvitationContentHtml() !== ''),
                Html::make(fn (): string => Blade::render('<x-honeypot livewire-model="extraFields" />')),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
                $this->getFormContentComponent(),
                Html::make(fn (): string => $this->getDiscoveryHintHtml())
                    ->visible(fn (): bool => $this->getDiscoveryHintHtml() !== ''),
                $this->getMultiFactorChallengeFormContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
            ]);
    }

    public function getMaxWidth(): Width
    {
        return Width::Medium;
    }

    public function hasLogo(): bool
    {
        return false;
    }

    public function getHeading(): string
    {
        return __('auth.login.welcome');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    public function authenticate(): ?LoginResponse
    {
        if ($this->authMethod === 'passkey') {
            $this->dispatch('passkey-login');

            return null;
        }

        if ($this->authMethod === 'social') {
            return null;
        }

        if ($this->authMethod === 'signup') {
            return $this->handleSignup();
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

    protected function handleSignup(): ?LoginResponse
    {
        $submittedEmail = EmailAddress::canonicalize((string) ($this->data['email'] ?? ''));

        if ($submittedEmail !== $this->discoveredEmail) {
            $this->authMethod = null;
            $this->passkeyUserHasPassword = false;
            $this->discoveredEmail = null;
            $this->discover();

            return null;
        }

        $this->protectAgainstSpam();

        $data = $this->form->getState();

        $email = EmailAddress::canonicalize((string) $data['email']);

        try {
            $user = resolve(CreateNewUser::class)->execute($email, (string) $data['password']);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'data.email' => __('validation.unique', ['attribute' => __('filament-panels::auth/pages/login.form.email.label')]),
            ]);
        }

        $invitation = $this->getTeamInvitationFromSession();

        if ($invitation && $invitation->email === $email && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        session()->put('fathom.track_signup', true);

        event(new Registered($user));

        $this->sendEmailVerificationNotification($user);

        Filament::auth()->login($user, remember: true);

        session()->regenerate();

        return resolve(LoginResponse::class);
    }

    private function sendEmailVerificationNotification(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $notification = resolve(VerifyEmail::class);
        $notification->url = Filament::getVerifyEmailUrl($user);

        $user->notify($notification);
    }

    private function getPasswordHelperText(): ?string
    {
        if ($this->authMethod !== 'signup') {
            return null;
        }

        return __('auth.login.password_helper');
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

        if ($this->authMethod === 'social') {
            return Blade::render(
                '<p class="mt-3 text-center text-sm text-gray-500 dark:text-gray-400">{{ __(\'auth.login.social_hint\') }}</p>',
            );
        }

        return '';
    }

    protected function discover(): void
    {
        $this->protectAgainstSpam();

        $email = EmailAddress::canonicalize((string) ($this->form->getState()['email'] ?? ''));

        $this->assertDiscoveryIsNotRateLimited($email);

        $this->discoveredEmail = $email;

        /**
         * The Timebox pads every branch to the same duration so the response
         * body stays the only existence oracle, not the response time.
         */
        resolve(Timebox::class)->call(function () use ($email): void {
            $user = User::query()->where('email', $email)->first();

            if ($user?->hasPasskey()) {
                $this->authMethod = 'passkey';
                $this->passkeyUserHasPassword = $user->hasPassword();
                $this->dispatch('passkey-login');

                return;
            }

            if ($user === null) {
                $this->authMethod = 'signup';

                return;
            }

            if ($user->hasPassword()) {
                $this->authMethod = 'password';

                return;
            }

            if (! Feature::active(SocialAuth::class)) {
                $this->authMethod = 'password';

                return;
            }

            $this->authMethod = $this->resolveOfferedProvider($user) === null
                ? 'password'
                : 'social';
        }, (int) config('auth.timebox_duration', 200_000));
    }

    private function assertDiscoveryIsNotRateLimited(string $email): void
    {
        $ip = request()->ip();

        $minuteKey = 'login-discover-ip:'.$ip;

        if (RateLimiter::tooManyAttempts($minuteKey, 20)) {
            throw ValidationException::withMessages([
                'data.email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($minuteKey)]),
            ]);
        }

        /**
         * The daily cap makes list enumeration expensive; a global per-email
         * limiter is omitted on purpose, it would let anyone lock a victim out.
         */
        $dayKey = 'login-discover-ip-day:'.$ip;

        if (RateLimiter::tooManyAttempts($dayKey, 300)) {
            throw ValidationException::withMessages([
                'data.email' => __('auth.throttle_long'),
            ]);
        }

        $emailKey = 'login-discover:'.$email.'|'.$ip;

        if (RateLimiter::tooManyAttempts($emailKey, 5)) {
            throw ValidationException::withMessages([
                'data.email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($emailKey)]),
            ]);
        }

        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($dayKey, 86400);
        RateLimiter::hit($emailKey, 60);
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
            ->label(fn (): string => match ($this->authMethod) {
                'password' => __('filament-panels::auth/pages/login.form.actions.authenticate.label'),
                'signup' => __('auth.login.sign_up'),
                default => __('auth.login.continue'),
            })
            ->submit('authenticate');
    }

    protected function getEmailFormComponent(): TextInput
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/login.form.email.label'))
            ->hiddenLabel()
            ->placeholder(__('auth.login.email_placeholder'))
            ->email()
            ->required()
            ->maxLength(255)
            ->autocomplete('username webauthn')
            ->autofocus()
            ->dehydrateStateUsing(fn (?string $state): string => EmailAddress::canonicalize((string) $state))
            ->default(fn (): ?string => $this->getTeamInvitationFromSession()?->email)
            ->rules(RegistrableEmail::rules(), condition: fn (): bool => $this->authMethod === 'signup')
            ->unique(table: fn (): ?string => $this->authMethod === 'signup' ? 'users' : null)
            ->live(onBlur: true)
            ->afterStateUpdated(function (): void {
                $this->authMethod = null;
                $this->passkeyUserHasPassword = false;
                $this->discoveredEmail = null;
            });
    }

    protected function getPasswordFormComponent(): TextInput
    {
        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/login.form.password.label'))
            ->hiddenLabel()
            ->placeholder(__('auth.login.password_placeholder'))
            ->hint(fn (): ?HtmlString => ($this->authMethod === 'password' && filament()->hasPasswordReset())
                ? new HtmlString(Blade::render('<x-filament::link :href="filament()->getRequestPasswordResetUrl()" tabindex="-1"> {{ __(\'filament-panels::auth/pages/login.actions.request_password_reset.label\') }}</x-filament::link>'))
                : null)
            ->helperText(fn (): ?string => $this->getPasswordHelperText())
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete(fn (): string => $this->authMethod === 'signup' ? 'new-password' : 'current-password')
            ->required()
            ->rule(Password::default(), condition: fn (): bool => $this->authMethod === 'signup')
            ->showAllValidationMessages()
            ->dehydrateStateUsing(fn (#[SensitiveParameter] string $state): string => $this->authMethod === 'signup' ? Hash::make($state) : $state)
            ->visible(fn (): bool => in_array($this->authMethod, ['password', 'signup'], true));
    }

    protected function getRememberFormComponent(): Hidden
    {
        return Hidden::make('remember')
            ->default(true);
    }
}
