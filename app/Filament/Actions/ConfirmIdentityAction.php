<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\User;
use App\Support\Auth\IdentityConfirmation;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\RateLimiter;
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

    private ?int $within = null;

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
     * Override the freshness window for this action. Has no effect when alwaysConfirm() is set.
     */
    public function within(int $seconds): static
    {
        $this->within = $seconds;

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

        $this->modalSubmitAction(function (Action $action): Action {
            if ($this->confirmingUser()->hasPasskey()) {
                $action->icon(Heroicon::FingerPrint);
            }

            return $action;
        });

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
            return ! IdentityConfirmation::confirmedRecently($this->within);
        }

        // alwaysConfirm ignores the freshness window (and any within() override): proof is
        // scoped to this attempt's own start time so the passkey ceremony re-entry terminates.
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
        $throttleKey = 'confirm-identity:'.$user->getAuthIdentifier();

        $passwordRule = new readonly class($user, $throttleKey) implements ValidationRule
        {
            public function __construct(
                private User $user,
                private string $throttleKey,
            ) {}

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (RateLimiter::tooManyAttempts($this->throttleKey, maxAttempts: 5)) {
                    $fail(__('profile.form.password.throttled', ['seconds' => RateLimiter::availableIn($this->throttleKey)]));

                    return;
                }

                if (! IdentityConfirmation::verifyPassword($this->user, (string) $value)) {
                    RateLimiter::hit($this->throttleKey, decaySeconds: 60);
                    $fail(__('auth.password'));

                    return;
                }

                RateLimiter::clear($this->throttleKey);
            }
        };

        return array_values(array_filter([
            $hasPasskey ? Hidden::make('use_password')->default(false) : null,
            $hasPasskey
                ? Placeholder::make('passkeyHint')
                    ->hiddenLabel()
                    ->content(__('profile.sections.passkeys.method_hint'))
                    ->visible(fn (Get $get): bool => ! (bool) $get('use_password'))
                : null,
            $hasPasskey
                ? Actions::make([
                    Action::make('usePassword')
                        ->label(__('profile.sections.passkeys.use_password'))
                        ->link()
                        ->action(function (Set $set): void {
                            $set('use_password', true);
                        }),
                ])->visible(fn (Get $get): bool => ! (bool) $get('use_password'))
                : null,
            TextInput::make('password')
                ->password()
                ->revealable()
                ->label(__('profile.form.password.label'))
                ->visible($usesPasswordField)
                ->required($usesPasswordField)
                ->rule($passwordRule),
        ]));
    }

    private function confirmingUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
