<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\Auth\ConfirmMfaEnrollment;
use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\BaseLivewireComponent;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Filament\Actions\Action;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
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
        $this->enabled = $this->authUser()->refresh()->hasEnabledTwoFactorAuthentication();
    }

    public function enableMfaAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('enableMfa')
            ->label(__('profile.sections.mfa.enable'))
            ->modalHeading(__('profile.sections.mfa.enable_heading'))
            ->modalDescription(__('profile.sections.mfa.identity_description'))
            ->modalWidth(Width::Medium)
            ->alwaysConfirm()
            ->resumable()
            ->operation('manage_mfa', 'enable')
            ->visible(fn (): bool => ! $this->enabled)
            ->modalSubmitActionLabel(__('profile.sections.mfa.continue'))
            ->confirmedUsing(function (): void {
                $user = $this->authUser();

                DB::transaction(function () use ($user): void {
                    $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                    AuthenticationSession::consumeOperation($lockedUser, 'manage_mfa', 'enable');

                    if ($lockedUser->hasEnabledTwoFactorAuthentication()) {
                        return;
                    }

                    resolve(EnableTwoFactorAuthentication::class)($lockedUser, force: true);

                    $this->pendingQrSvg = $lockedUser->twoFactorQrCodeSvg();
                    $this->pendingSecret = Fortify::currentEncrypter()->decrypt((string) $lockedUser->two_factor_secret);
                });

                $this->refreshState();

                if (! $this->enabled) {
                    $this->replaceMountedAction('confirmMfa');
                }
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
                    ->required(),
            ])
            ->action(function (array $data, Schema $schema): void {
                try {
                    resolve(ConfirmMfaEnrollment::class)->execute($this->authUser(), (string) $this->pendingSecret, (string) $data['code']);
                } catch (ValidationException) {
                    throw ValidationException::withMessages([
                        $schema->getStatePath().'.code' => __('profile.sections.mfa.code_invalid'),
                    ]);
                }

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
            ->resumable()
            ->operation('manage_mfa', 'disable')
            ->visible(fn (): bool => $this->enabled)
            ->modalSubmitActionLabel(__('profile.sections.mfa.disable'))
            ->confirmedUsing(function (): void {
                $user = $this->authUser();

                AuthenticationSession::consumeOperation($user, 'manage_mfa', 'disable');

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
            ->resumable()
            ->operation('manage_mfa', 'show_recovery_codes')
            ->visible(fn (): bool => $this->enabled)
            ->confirmedUsing(function (): void {
                $user = $this->authUser();

                AuthenticationSession::consumeOperation($user, 'manage_mfa', 'show_recovery_codes');

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
            ->resumable()
            ->operation('manage_mfa', 'regenerate_recovery_codes')
            ->visible(fn (): bool => $this->enabled)
            ->confirmedUsing(function (): void {
                $user = $this->authUser();

                AuthenticationSession::consumeOperation($user, 'manage_mfa', 'regenerate_recovery_codes');

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
}
