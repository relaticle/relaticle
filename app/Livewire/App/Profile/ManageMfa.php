<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\Auth\ConfirmMfaEnrollment;
use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\BaseLivewireComponent;
use App\Support\Auth\AuthenticationSession;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\View\View;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Enrolment and removal for the second factor, the surface the rest of the
 * authentication work assumed existed. Every write here mints and spends a
 * `manage_mfa` grant, so the same proof the direct Fortify routes demand is
 * demanded here too.
 */
final class ManageMfa extends BaseLivewireComponent
{
    #[Locked]
    public bool $enabled = false;

    /**
     * Held only while the enrolment modal is open, and only after identity was
     * proven. The secret is not a credential until the user confirms a code
     * against it, but it still never reaches the page before that proof.
     */
    #[Locked]
    public ?string $pendingQrSvg = null;

    #[Locked]
    public ?string $pendingSecret = null;

    /**
     * @var list<string>
     */
    #[Locked]
    public array $revealedRecoveryCodes = [];

    public function mount(): void
    {
        $this->refreshState();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('profile.sections.mfa.title'))
                    ->description(__('profile.sections.mfa.description'))
                    ->aside()
                    ->schema([
                        ViewField::make('mfa')
                            ->hiddenLabel()
                            ->view('components.mfa-section'),
                    ]),
            ]);
    }

    public function refreshState(): void
    {
        $this->enabled = $this->authUser()->hasEnabledTwoFactorAuthentication();
    }

    /**
     * Generates the secret only once identity is proven, then holds it in
     * component state until the user confirms a code. An abandoned modal
     * leaves the account exactly as it was: the secret is written but
     * `two_factor_confirmed_at` stays null, so nothing is enforced yet.
     */
    public function enableMfaAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('enableMfa')
            ->label(__('profile.sections.mfa.enable'))
            ->modalHeading(__('profile.sections.mfa.enable_heading'))
            ->modalDescription(__('profile.sections.mfa.enable_description'))
            ->modalWidth(Width::Medium)
            ->alwaysConfirm()
            ->operation('manage_mfa')
            ->visible(fn (): bool => ! $this->enabled)
            ->modalSubmitActionLabel(__('profile.sections.mfa.enable'))
            ->confirmedUsing(function (): void {
                $user = $this->authUser();

                // Spend the grant before writing the secret: the fingerprint binds
                // two_factor_secret, so generating one invalidates the grant that
                // authorized it and nothing downstream could ever consume it.
                AuthenticationSession::consumeOperation($user, 'manage_mfa', null);

                resolve(EnableTwoFactorAuthentication::class)($user, force: true);

                $fresh = $user->fresh();
                $this->pendingQrSvg = $fresh?->twoFactorQrCodeSvg();
                $this->pendingSecret = Fortify::currentEncrypter()->decrypt((string) $fresh?->two_factor_secret);

                // Close this modal rather than halting it: a still-mounted action
                // makes the follow-up confirm resolve as a nested action inside it.
                $this->unmountAction();
            });
    }

    /**
     * Spends the grant minted by the enrolment modal at the moment the second
     * factor actually starts being enforced, never earlier.
     */
    public function confirmMfaAction(): Action
    {
        return Action::make('confirmMfa')
            ->label(__('profile.sections.mfa.confirm'))
            ->modalHeading(__('profile.sections.mfa.enable_heading'))
            ->modalWidth(Width::Medium)
            ->schema([
                TextInput::make('code')
                    ->label(__('profile.sections.mfa.code_label'))
                    ->required()
                    ->rule($this->pendingCodeRule()),
            ])
            ->action(function (): void {
                resolve(ConfirmMfaEnrollment::class)->execute($this->authUser());

                $this->pendingQrSvg = null;
                $this->pendingSecret = null;
                $this->refreshState();

                Notification::make()
                    ->title(__('profile.sections.mfa.enabled_notification'))
                    ->success()
                    ->send();
            });
    }

    public function disableMfaAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('disableMfa')
            ->label(__('profile.sections.mfa.disable'))
            ->color('danger')
            ->modalHeading(__('profile.sections.mfa.disable_heading'))
            ->modalDescription(__('profile.sections.mfa.disable_description'))
            ->modalWidth(Width::Medium)
            ->alwaysConfirm()
            ->operation('manage_mfa')
            ->visible(fn (): bool => $this->enabled)
            ->modalSubmitActionLabel(__('profile.sections.mfa.disable'))
            ->confirmedUsing(function (): void {
                $user = $this->authUser();

                AuthenticationSession::consumeOperation($user, 'manage_mfa', null);

                resolve(DisableTwoFactorAuthentication::class)($user);

                $this->revealedRecoveryCodes = [];
                $this->refreshState();

                Notification::make()
                    ->title(__('profile.sections.mfa.disabled_notification'))
                    ->success()
                    ->send();
            });
    }

    public function showRecoveryCodesAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('showRecoveryCodes')
            ->label(__('profile.sections.mfa.recovery_show'))
            ->modalHeading(__('profile.sections.mfa.recovery_heading'))
            ->modalDescription(__('profile.sections.mfa.recovery_description'))
            ->modalWidth(Width::Medium)
            ->alwaysConfirm()
            ->operation('manage_mfa')
            ->visible(fn (): bool => $this->enabled)
            ->confirmedUsing(function (): void {
                $user = $this->authUser();

                AuthenticationSession::consumeOperation($user, 'manage_mfa', null);

                $this->revealedRecoveryCodes = $this->readRecoveryCodes();
            });
    }

    public function regenerateRecoveryCodesAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('regenerateRecoveryCodes')
            ->label(__('profile.sections.mfa.recovery_regenerate'))
            ->modalHeading(__('profile.sections.mfa.recovery_regenerate'))
            ->modalDescription(__('profile.sections.mfa.recovery_description'))
            ->modalWidth(Width::Medium)
            ->alwaysConfirm()
            ->operation('manage_mfa')
            ->visible(fn (): bool => $this->enabled)
            ->confirmedUsing(function (): void {
                $user = $this->authUser();

                AuthenticationSession::consumeOperation($user, 'manage_mfa', null);

                resolve(GenerateNewRecoveryCodes::class)($user);

                $this->revealedRecoveryCodes = $this->readRecoveryCodes();

                Notification::make()
                    ->title(__('profile.sections.mfa.recovery_regenerated'))
                    ->success()
                    ->send();
            });
    }

    public function render(): View
    {
        return view('livewire.app.profile.manage-mfa');
    }

    /**
     * @return list<string>
     */
    private function readRecoveryCodes(): array
    {
        try {
            return array_values($this->authUser()->fresh()?->recoveryCodes() ?? []);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Reads the secret through a closure, not a captured value: the action is
     * built before enrolment generates one, so a snapshot would always be null.
     */
    private function pendingCodeRule(): ValidationRule
    {
        return new readonly class(fn (): ?string => $this->pendingSecret) implements ValidationRule
        {
            public function __construct(private Closure $secret) {}

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                $secret = ($this->secret)();

                if (! is_string($secret) || ! resolve(TwoFactorAuthenticationProvider::class)->verify($secret, (string) $value)) {
                    $fail(__('profile.sections.mfa.code_invalid'));
                }
            }
        };
    }
}
