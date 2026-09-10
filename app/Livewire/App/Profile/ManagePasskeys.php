<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Actions\Passkeys\RenamePasskey;
use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\BaseLivewireComponent;
use App\Support\Auth\AuthenticationSession;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkey;
use Livewire\Attributes\Locked;

final class ManagePasskeys extends BaseLivewireComponent
{
    /**
     * @var array<int, array{id: int, name: string, authenticator: ?string, created_at_diff: string, last_used_at_diff: ?string}>
     */
    #[Locked]
    public array $passkeys = [];

    public function mount(): void
    {
        $this->loadPasskeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('profile.sections.passkeys.title'))
                    ->description(__('profile.sections.passkeys.description'))
                    ->aside()
                    ->schema([
                        ViewField::make('passkeys')
                            ->hiddenLabel()
                            ->view('components.passkeys-section'),
                    ]),
            ]);
    }

    public function loadPasskeys(): void
    {
        $this->passkeys = $this->authUser()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn (Passkey $passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at?->diffForHumans() ?? '',
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Confirm the user's identity (passkey ceremony or password), then run the browser
     * registration ceremony. A user with neither a password nor a passkey has nothing
     * left to prove inline: ConfirmIdentityAction halts instead of proceeding, and the
     * add_passkey grant this mints is only spent later, at the passkey.store request the
     * browser makes once the ceremony completes (see RequireOperationGrant).
     *
     * No name is collected here. The AAGUID that identifies the authenticator only exists
     * once the ceremony has completed, so a name asked for up front is a guess at
     * something we are about to be told; the browser sends a device-derived fallback and
     * the list prefers the resolved authenticator label over it.
     */
    public function registerPasskeyAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('registerPasskey')
            ->label(__('profile.sections.passkeys.add_passkey'))
            ->modalHeading(__('profile.sections.passkeys.add_passkey'))
            ->modalDescription(__('profile.sections.passkeys.add_description'))
            ->modalWidth(Width::Medium)
            ->alwaysConfirm()
            ->resumable()
            ->operation('add_passkey')
            ->modalSubmitActionLabel(__('profile.sections.passkeys.register'))
            ->confirmedUsing(function (Action $action): void {
                $this->dispatch('passkey-register');

                $action->halt();
            });
    }

    public function renamePasskeyAction(): Action
    {
        return Action::make('renamePasskey')
            ->label(__('profile.sections.passkeys.rename'))
            ->link()
            ->size(Size::Small)
            ->modalHeading(__('profile.sections.passkeys.rename'))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('profile.sections.passkeys.save'))
            ->fillForm(fn (array $arguments): array => [
                'name' => $this->authUser()->passkeys()
                    ->whereKey((int) ($arguments['passkeyId'] ?? 0))
                    ->value('name') ?? '',
            ])
            ->schema([
                TextInput::make('name')
                    ->label(__('profile.sections.passkeys.name_label'))
                    ->placeholder(__('profile.sections.passkeys.name_placeholder'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $arguments, array $data, RenamePasskey $renamePasskey): void {
                $passkey = $this->authUser()->passkeys()
                    ->whereKey((int) ($arguments['passkeyId'] ?? 0))
                    ->first();

                if (! $passkey instanceof Passkey) {
                    return;
                }

                $renamePasskey->execute($this->authUser(), $passkey, $data['name']);

                $this->loadPasskeys();

                $this->sendNotification(__('profile.notifications.passkey_renamed.success'));
            });
    }

    public function notifyRegistrationFailed(): void
    {
        $this->sendNotification(
            __('profile.notifications.passkey_registration_failed.title'),
            type: 'danger',
        );
    }

    public function deletePasskeyAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('deletePasskey')
            ->modalHeading(__('profile.sections.passkeys.remove_confirm_title'))
            ->modalDescription(__('profile.sections.passkeys.remove_confirm'))
            ->modalSubmitActionLabel(__('profile.sections.passkeys.remove'))
            ->link()
            ->size(Size::Small)
            ->color('danger')
            ->alwaysConfirm()
            ->operation('delete_passkey', fn (array $arguments): ?string => isset($arguments['passkeyId']) ? (string) $arguments['passkeyId'] : null)
            ->confirmedUsing(function (?string $operationTarget, DeletePasskey $deletePasskey): void {
                $this->performDelete((int) ($operationTarget ?? 0), $deletePasskey);
            });
    }

    private function performDelete(int $passkeyId, DeletePasskey $deletePasskey): void
    {
        $user = $this->authUser();

        // Re-check and spend the grant against the id actually being deleted,
        // at the point of the write. Proving identity earlier in this request
        // authorizes nothing by itself; only this call does.
        AuthenticationSession::consumeOperation($user, 'delete_passkey', (string) $passkeyId);

        $passkey = $user->passkeys()->whereKey($passkeyId)->first();

        if (! $passkey instanceof Passkey) {
            return;
        }

        try {
            $deletePasskey($user, $passkey);
        } catch (ValidationException $exception) {
            $this->sendNotification($exception->validator->errors()->first(), type: 'danger');

            return;
        }

        $this->loadPasskeys();

        $this->sendNotification(__('profile.notifications.passkey_removed.success'));
    }

    public function render(): View
    {
        return view('livewire.app.profile.manage-passkeys');
    }
}
