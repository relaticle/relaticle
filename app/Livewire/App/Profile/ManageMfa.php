<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\Auth\ConfirmMfaEnrollment;
use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\BaseLivewireComponent;
use App\Support\Auth\AuthenticationSession;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\OneTimeCodeInput;
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

final class ManageMfa extends BaseLivewireComponent
{
    #[Locked]
    public bool $enabled = false;

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

    public function enableMfaAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('enableMfa')
            ->label(__('profile.sections.mfa.enable'))
            ->modalHeading(__('profile.sections.mfa.enable_heading'))
            ->modalDescription(__('profile.sections.mfa.identity_description'))
            ->modalWidth(Width::Medium)
            ->alwaysConfirm()
            ->operation('manage_mfa')
            ->visible(fn (): bool => ! $this->enabled)
            ->modalSubmitActionLabel(__('profile.sections.mfa.continue'))
            ->confirmedUsing(function (): void {
                $user = $this->authUser();

                AuthenticationSession::consumeOperation($user, 'manage_mfa', null);

                resolve(EnableTwoFactorAuthentication::class)($user, force: true);

                $fresh = $user->fresh();
                $this->pendingQrSvg = $fresh?->twoFactorQrCodeSvg();
                $this->pendingSecret = Fortify::currentEncrypter()->decrypt((string) $fresh?->two_factor_secret);

                $this->replaceMountedAction('confirmMfa');
            });
    }

    public function confirmMfaAction(): Action
    {
        return Action::make('confirmMfa')
            ->label(__('profile.sections.mfa.confirm'))
            ->modalHeading(__('profile.sections.mfa.setup_heading'))
            ->modalDescription(__('profile.sections.mfa.enable_description'))
            ->modalWidth(Width::Medium)
            ->closeModalByClickingAway(false)
            ->visible(fn (): bool => $this->pendingSecret !== null && ! $this->enabled)
            ->modalContent(fn (): View => view('components.mfa-setup'))
            ->modalSubmitActionLabel(__('profile.sections.mfa.verify'))
            ->schema([
                OneTimeCodeInput::make('code')
                    ->autofocus()
                    ->label(__('profile.sections.mfa.code_label'))
                    ->required()
                    ->rule($this->pendingCodeRule()),
            ])
            ->action(function (): void {
                resolve(ConfirmMfaEnrollment::class)->execute($this->authUser());

                $this->pendingQrSvg = null;
                $this->pendingSecret = null;
                $this->refreshState();
                $this->revealedRecoveryCodes = $this->readRecoveryCodes();
                $this->replaceMountedAction('saveRecoveryCodes');

                Notification::make()
                    ->title(__('profile.sections.mfa.enabled_notification'))
                    ->success()
                    ->send();
            });
    }

    public function saveRecoveryCodesAction(): Action
    {
        return Action::make('saveRecoveryCodes')
            ->modalHeading(__('profile.sections.mfa.recovery_save_heading'))
            ->modalDescription(__('profile.sections.mfa.recovery_description'))
            ->modalWidth(Width::Medium)
            ->closeModalByClickingAway(false)
            ->visible(fn (): bool => $this->enabled && $this->revealedRecoveryCodes !== [])
            ->modalContent(fn (): View => view('components.mfa-recovery-codes'))
            ->modalSubmitActionLabel(__('profile.sections.mfa.recovery_saved'))
            ->modalCancelAction(false)
            ->action(function (): void {
                $this->revealedRecoveryCodes = [];
            });
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $finishingEnrollment = $this->getMountedAction()?->getName() === 'saveRecoveryCodes';

        parent::unmountAction($cancelParentActions);

        if ($this->mountedActions === []) {
            $this->pendingQrSvg = null;
            $this->pendingSecret = null;

            if ($finishingEnrollment) {
                $this->revealedRecoveryCodes = [];
            }
        }
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
