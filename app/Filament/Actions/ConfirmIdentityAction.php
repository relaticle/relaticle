<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\User;
use App\Support\Auth\IdentityConfirmation;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;
use LogicException;

/**
 * Re-entrant identity confirmation as a reusable Filament action.
 *
 * First invocation: if the gate is not satisfied and the user has a passkey, it
 * dispatches the browser confirm ceremony and halts (modal stays open). The global
 * JS handler runs the WebAuthn assertion — which refreshes auth.password_confirmed_at
 * server-side — then re-invokes callMountedAction. On re-entry the gate is satisfied
 * and confirmedUsing() runs. The password path validates and marks confirmed inline,
 * with no ceremony.
 */
final class ConfirmIdentityAction extends Action
{
    private ?Closure $confirmedUsing = null;

    /** @var array<int, Component> */
    private array $prependedSchema = [];

    public static function getDefaultName(): string
    {
        return 'confirmIdentity';
    }

    public function confirmedUsing(Closure $callback): static
    {
        $this->confirmedUsing = $callback;

        return $this;
    }

    /**
     * @param  array<int, Component>  $components
     */
    public function prependSchema(array $components): static
    {
        $this->prependedSchema = $components;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresConfirmation();

        $this->schema(fn (): array => [
            ...$this->prependedSchema,
            ...$this->identityFields(),
        ]);

        $this->action(function (array $data, Action $action): mixed {
            $user = $this->confirmingUser();

            if (! IdentityConfirmation::satisfied($user)) {
                if ($user->hasPasskey() && blank($data['password'] ?? null)) {
                    $livewire = $this->getLivewire();

                    assert($livewire instanceof LivewireComponent);

                    $livewire->dispatch(
                        'confirm-identity-ceremony',
                        componentId: $livewire->getId(),
                    );

                    $action->halt();
                }

                if (! IdentityConfirmation::verifyPassword($user, (string) ($data['password'] ?? ''))) {
                    throw ValidationException::withMessages([
                        'password' => __('auth.password'),
                    ]);
                }

                IdentityConfirmation::markConfirmed();
            }

            throw_if(! $this->confirmedUsing instanceof Closure, LogicException::class, 'ConfirmIdentityAction: confirmedUsing callback is not set.');

            return $this->evaluate($this->confirmedUsing, ['action' => $action]);
        });
    }

    /**
     * @return array<int, Component>
     */
    private function identityFields(): array
    {
        $user = $this->confirmingUser();

        if (! $user->hasPassword()) {
            return [];
        }

        $hasPasskey = $user->hasPasskey();
        $usesPasswordField = fn (Get $get): bool => ! $hasPasskey || (bool) $get('use_password');

        return array_values(array_filter([
            $hasPasskey
                ? Checkbox::make('use_password')
                    ->label(__('profile.sections.passkeys.confirm_with_password'))
                    ->live()
                : null,
            TextInput::make('password')
                ->password()
                ->revealable()
                ->label(__('profile.form.password.label'))
                ->visible($usesPasswordField)
                ->required($usesPasswordField)
                ->rule('current_password')
                ->validationMessages(['current_password' => __('auth.password')]),
        ]));
    }

    private function confirmingUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
