<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\BaseLivewireComponent;
use App\Support\Auth\IdentityConfirmation;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
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
     * Collect a passkey name, confirm the user's identity (passkey ceremony, password
     * fallback, or session alone for passwordless accounts), then run the browser
     * registration ceremony. markConfirmed() is always called before dispatching so the
     * subsequent register POST satisfies the vendor RequirePassword middleware — even for
     * passwordless accounts the identity gate would otherwise short-circuit.
     */
    public function registerPasskeyAction(): ConfirmIdentityAction
    {
        return ConfirmIdentityAction::make('registerPasskey')
            ->label(__('profile.sections.passkeys.add_passkey'))
            ->modalHeading(__('profile.sections.passkeys.add_passkey'))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('profile.sections.passkeys.register'))
            ->prependSchema([
                TextInput::make('name')
                    ->label(__('profile.sections.passkeys.name_label'))
                    ->placeholder(__('profile.sections.passkeys.name_placeholder'))
                    ->required()
                    ->maxLength(255),
            ])
            ->confirmedUsing(function (array $data, Action $action): void {
                IdentityConfirmation::markConfirmed();

                $this->dispatch('passkey-register', name: $data['name']);

                $action->halt();
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
            ->color('danger')
            ->confirmedUsing(function (array $arguments, DeletePasskey $deletePasskey): void {
                $this->performDelete((int) ($arguments['passkeyId'] ?? 0), $deletePasskey);
            });
    }

    private function performDelete(int $passkeyId, DeletePasskey $deletePasskey): void
    {
        $user = $this->authUser();

        $passkey = $user->passkeys()->whereKey($passkeyId)->first();

        if (! $passkey instanceof Passkey) {
            return;
        }

        $deletePasskey($user, $passkey);

        $this->loadPasskeys();

        $this->sendNotification(__('profile.notifications.passkey_removed.success'));
    }

    public function render(): View
    {
        return view('livewire.app.profile.manage-passkeys');
    }
}
