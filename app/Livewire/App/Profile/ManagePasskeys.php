<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Livewire\BaseLivewireComponent;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
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

    /**
     * Collect a passkey name in a Filament modal, then satisfy the RequirePassword
     * middleware on the registration routes before the browser WebAuthn ceremony.
     * Strongest-available confirmation is offered first: users who already own a
     * passkey confirm with a passkey assertion (the vendor confirm endpoint sets
     * auth.password_confirmed_at), with an opt-in password fallback. First-passkey
     * registration keeps the password gate (there is no passkey to confirm with yet),
     * and passwordless (social) accounts are confirmed by their active session alone.
     */
    public function registerPasskeyAction(): Action
    {
        $hasPassword = $this->authUser()->hasPassword();
        $canConfirmWithPasskey = $hasPassword && $this->authUser()->passkeys()->exists();

        $confirmsWithPassword = fn (Get $get): bool => ! $canConfirmWithPasskey || (bool) $get('use_password');

        return Action::make('registerPasskey')
            ->label(__('profile.sections.passkeys.add_passkey'))
            ->modalHeading(__('profile.sections.passkeys.add_passkey'))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('profile.sections.passkeys.register'))
            ->schema(array_filter([
                TextInput::make('name')
                    ->label(__('profile.sections.passkeys.name_label'))
                    ->placeholder(__('profile.sections.passkeys.name_placeholder'))
                    ->required()
                    ->maxLength(255),
                $canConfirmWithPasskey
                    ? Checkbox::make('use_password')
                        ->label(__('profile.sections.passkeys.confirm_with_password'))
                        ->live()
                    : null,
                $hasPassword
                    ? TextInput::make('password')
                        ->label(__('profile.form.current_password.label'))
                        ->helperText(__('profile.sections.passkeys.password_help'))
                        ->password()
                        ->revealable()
                        ->visible($confirmsWithPassword)
                        ->required($confirmsWithPassword)
                        ->rule('current_password')
                        ->validationMessages(['current_password' => __('auth.password')])
                    : null,
            ]))
            ->action(function (array $data) use ($canConfirmWithPasskey): void {
                if ($canConfirmWithPasskey && blank($data['password'] ?? null)) {
                    $this->dispatch('passkey-confirm-then-register', name: $data['name']);

                    return;
                }

                session()->put('auth.password_confirmed_at', time());

                $this->dispatch('passkey-register-confirmed', name: $data['name']);
            });
    }

    public function notifyRegistrationFailed(): void
    {
        $this->sendNotification(
            __('profile.notifications.passkey_registration_failed.title'),
            type: 'danger',
        );
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
