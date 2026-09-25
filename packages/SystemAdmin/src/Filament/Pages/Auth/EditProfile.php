<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Pages\Auth;

use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Relaticle\SystemAdmin\Actions\Passkeys\DeletePasskey;
use Relaticle\SystemAdmin\Http\Requests\PasskeyRegistrationRequest;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Models\SystemAdministratorPasskey;
use SensitiveParameter;

final class EditProfile extends BaseEditProfile
{
    /**
     * @var array<int, array{id: int, name: string, authenticator: ?string, created_at_diff: string, last_used_at_diff: ?string}>
     */
    #[Locked]
    public array $passkeys = [];

    public function mount(): void
    {
        parent::mount();

        $this->loadPasskeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getTimezoneFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
            ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
            $this->getPasskeysContentComponent(),
        ]);
    }

    public function loadPasskeys(): void
    {
        $this->passkeys = $this->administrator()->passkeys()
            ->latest()
            ->get()
            ->map(fn (SystemAdministratorPasskey $passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at?->diffForHumans() ?? '',
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->all();
    }

    public function registerPasskeyAction(): Action
    {
        return Action::make('registerPasskey')
            ->label(__('Add a passkey'))
            ->modalHeading(__('Add a passkey'))
            ->modalDescription(__('Your browser will ask you to confirm with the device unlock you already use.'))
            ->modalSubmitActionLabel(__('Continue'))
            ->icon(Heroicon::FingerPrint)
            ->link()
            ->visible(fn (): bool => SystemAdministratorPasskey::hasDedicatedRelyingParty())
            ->schema([
                $this->appAuthenticationCodeInput(),
            ])
            ->rateLimit(5)
            // The ceremony runs in the browser, so the modal stays open until the
            // credential comes back; the view closes it.
            ->action(function (Action $action): void {
                session()->put(PasskeyRegistrationRequest::GRANT_KEY, now()->addMinutes(2));

                $this->dispatch('sysadmin-passkey-register');

                $action->halt();
            });
    }

    public function deletePasskeyAction(): Action
    {
        return Action::make('deletePasskey')
            ->label(__('Remove'))
            ->modalHeading(__('Remove this passkey'))
            ->modalDescription(__('This device will no longer be able to sign in to the system admin panel.'))
            ->modalSubmitActionLabel(__('Remove'))
            ->link()
            ->size(Size::Small)
            ->color('danger')
            ->schema([
                $this->appAuthenticationCodeInput(),
            ])
            ->rateLimit(5)
            ->action(function (array $arguments, DeletePasskey $deletePasskey): void {
                $administrator = $this->administrator();

                $passkey = $administrator->passkeys()
                    ->whereKey($arguments['passkeyId'] ?? 0)
                    ->first();

                if (! $passkey instanceof SystemAdministratorPasskey) {
                    return;
                }

                $deletePasskey->execute($administrator, $passkey);

                $this->loadPasskeys();
            });
    }

    public function notifyPasskeyRegistrationFailed(): void
    {
        Notification::make()
            ->title(__('That passkey could not be registered.'))
            ->danger()
            ->send();
    }

    private function getPasskeysContentComponent(): Section
    {
        return Section::make()
            ->label(__('Passkeys'))
            ->description(__('Sign in with a device unlock instead of a password and a code.'))
            ->compact()
            ->secondary()
            // Still shown without a dedicated relying party when credentials already
            // exist, so they stay removable after the panel moves back onto one host.
            ->visible(fn (): bool => SystemAdministratorPasskey::hasDedicatedRelyingParty() || filled($this->passkeys))
            ->schema([
                View::make('system-admin::profile.passkeys'),
                ActionsComponent::make([$this->registerPasskeyAction()]),
            ]);
    }

    private function appAuthenticationCodeInput(): OneTimeCodeInput
    {
        return OneTimeCodeInput::make('code')
            ->label(__('Enter the 6-digit code from the authenticator app'))
            ->required()
            ->rule($this->appAuthenticationCodeRule());
    }

    // Mirrors Filament's DisableAppAuthenticationAction rule: managing a sign-in
    // credential needs the same proof as turning the second factor off.
    private function appAuthenticationCodeRule(): Closure
    {
        return fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
            $administrator = $this->administrator();
            $rateLimitingKey = 'sysadmin-passkey-management:'.$administrator->getAuthIdentifier();

            if (RateLimiter::tooManyAttempts($rateLimitingKey, maxAttempts: 5)) {
                $fail(__('filament-panels::auth/multi-factor/app/actions/disable.modal.form.code.messages.rate_limited'));

                return;
            }

            RateLimiter::hit($rateLimitingKey);

            $secret = $administrator->getAppAuthenticationSecret();

            if (is_string($value) && filled($secret) && $this->appAuthentication()->verifyCode($value, $secret, shouldPreventCodeReuse: true)) {
                return;
            }

            $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.code.messages.invalid'));
        };
    }

    // A fresh AppAuthentication::make() would silently drop a codeWindow() or
    // recoverable() the panel configured.
    private function appAuthentication(): AppAuthentication
    {
        $provider = Filament::getCurrentPanel()?->getMultiFactorAuthenticationProviders()['app'] ?? null;

        return $provider instanceof AppAuthentication ? $provider : AppAuthentication::make();
    }

    private function administrator(): SystemAdministrator
    {
        $administrator = Filament::auth()->user();

        abort_unless($administrator instanceof SystemAdministrator, 403);

        return $administrator;
    }

    /**
     * Validated on write as well as constrained by the options: the column is a plain
     * string, and an identifier that PHP no longer recognises would otherwise reach
     * the timezone resolver and silently drop the administrator back to server time.
     */
    private function getTimezoneFormComponent(): Select
    {
        $identifiers = timezone_identifiers_list();

        return Select::make('timezone')
            ->label('Timezone')
            ->options(array_combine($identifiers, $identifiers))
            ->searchable()
            ->native(false)
            ->placeholder('UTC (server time)')
            ->rule(Rule::in($identifiers))
            ->helperText('Timestamps across this panel render in this zone, always labelled with it. Leave unset to read the same UTC values as Horizon, Flare and the server logs.');
    }
}
