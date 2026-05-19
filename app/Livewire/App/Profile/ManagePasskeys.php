<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Livewire\BaseLivewireComponent;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
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
     * @throws ValidationException
     */
    public function deletePasskey(int $passkeyId, ?string $password, DeletePasskey $deletePasskey): void
    {
        $user = $this->authUser();

        if ($user->hasPassword() && ! Hash::check((string) $password, (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $passkey = $user->passkeys()->whereKey($passkeyId)->first();

        if (! $passkey instanceof Passkey) {
            return;
        }

        $deletePasskey($user, $passkey);

        $this->loadPasskeys();

        $this->sendNotification(__('profile.notifications.passkey_removed.success'));
    }

    public function deletePasskeyAction(): Action
    {
        $hasPassword = $this->authUser()->hasPassword();

        return Action::make('deletePasskey')
            ->requiresConfirmation()
            ->modalHeading(__('profile.sections.passkeys.remove_confirm_title'))
            ->modalDescription(__('profile.sections.passkeys.remove_confirm'))
            ->modalSubmitActionLabel(__('profile.sections.passkeys.remove'))
            ->color('danger')
            ->schema($hasPassword ? [
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->label(__('profile.form.password.label'))
                    ->required(),
            ] : [])
            ->action(function (array $arguments, array $data, DeletePasskey $deletePasskey): void {
                $this->deletePasskey(
                    (int) ($arguments['passkeyId'] ?? 0),
                    $data['password'] ?? null,
                    $deletePasskey,
                );
            });
    }

    public function render(): View
    {
        return view('livewire.app.profile.manage-passkeys');
    }
}
