<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\User;
use App\Support\Auth\IdentityConfirmation;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
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
 *
 * Irreversible actions opt into alwaysConfirm(): the freshness window is ignored and a
 * fresh proof is demanded on every attempt. Re-entry is scoped to the attempt's own
 * start time (a hidden timestamp) rather than the global window, so the passkey ceremony
 * — which refreshes auth.password_confirmed_at — still terminates the loop on re-entry.
 */
final class ConfirmIdentityAction extends Action
{
    private ?Closure $confirmedUsing = null;

    private bool $alwaysConfirm = false;

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

    public function alwaysConfirm(bool $condition = true): static
    {
        $this->alwaysConfirm = $condition;

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
            ...$this->attemptMarker(),
            ...$this->identityFields(),
        ]);

        $this->action(function (array $data, Action $action): mixed {
            $user = $this->confirmingUser();

            if ($this->confirmationRequired($user, $data)) {
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
     * @param  array<string, mixed>  $data
     */
    private function confirmationRequired(User $user, array $data): bool
    {
        if (! $user->hasPassword() && ! $user->hasPasskey()) {
            return false;
        }

        if (! $this->alwaysConfirm) {
            return ! IdentityConfirmation::confirmedRecently();
        }

        $attemptStartedAt = (int) ($data['confirm_started_at'] ?? 0);
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        return $confirmedAt < $attemptStartedAt;
    }

    /**
     * Pins the moment this attempt began so re-confirmation is scoped to the attempt,
     * not the global freshness window. Persists across the halt/re-entry cycle.
     *
     * @return array<int, Component>
     */
    private function attemptMarker(): array
    {
        if (! $this->alwaysConfirm) {
            return [];
        }

        return [
            Hidden::make('confirm_started_at')->default(fn (): string => (string) time()),
        ];
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
