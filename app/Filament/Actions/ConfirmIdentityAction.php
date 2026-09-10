<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\SocialiteProvider;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\IdentityConfirmation;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;
use LogicException;

/**
 * Re-entrant identity confirmation as a reusable Filament action.
 *
 * First invocation: if the gate is not satisfied and the user has a passkey, it
 * dispatches the browser confirm ceremony and halts (modal stays open). The
 * ceremony runs through PasskeyConfirmationController, which proves identity,
 * applies enrolled MFA, and marks the session confirmed itself; the JS handler
 * then re-invokes callMountedAction. On re-entry the gate is satisfied and
 * confirmedUsing() runs. The password path validates and marks confirmed
 * inline, with no ceremony. A user with neither a password nor a passkey has
 * no inline proof to give; if a linked provider offers one, it becomes the
 * modal's only footer action, a round trip the user must actually complete,
 * never a bypass.
 *
 * Irreversible actions opt into alwaysConfirm(): the freshness window is
 * ignored and a fresh proof is demanded on every attempt. Re-entry is scoped to
 * a server-minted attempt id (a hidden field the client cannot forge a value
 * for) rather than the global window, so the passkey ceremony still terminates
 * the loop on re-entry. operation() additionally binds that attempt to one of
 * AuthenticationSession's allowlisted operations, so a matching write action
 * can later require and consume the same grant.
 */
final class ConfirmIdentityAction extends Action
{
    private ?Closure $confirmedUsing = null;

    private bool $alwaysConfirm = false;

    private ?int $within = null;

    private ?string $operation = null;

    private bool $resumable = false;

    private Closure|string|null $target = null;

    private bool $providerAccountResolved = false;

    private ?UserSocialAccount $providerAccount = null;

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
     * Bind this attempt to one of AuthenticationSession's allowlisted operations,
     * so a later write action can require and consume the same grant. Only
     * meaningful alongside alwaysConfirm().
     */
    public function operation(string $operation, Closure|string|null $target = null): static
    {
        $this->operation = $operation;
        $this->target = $target;

        return $this;
    }

    /**
     * Re-open this action when the provider round trip returns. Off by default:
     * an action that reads page form state would come back to an empty one.
     */
    public function resumable(bool $condition = true): static
    {
        $this->resumable = $condition;

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

        $this->modalSubmitAction(function (Action $action): Action|false {
            if ($this->providerProofPending()) {
                return false;
            }

            if ($this->confirmingUser()->hasPasskey()) {
                $action->icon(Heroicon::FingerPrint);
            }

            return $action;
        });

        $this->modalCancelAction(fn (Action $action): Action|false => $this->providerProofPending() ? false : $action);

        $this->extraModalFooterActions(fn (): array => array_filter([$this->providerConfirmationAction()]));

        $this->action(function (array $data, Action $action, ?Schema $schema): mixed {
            $user = $this->confirmingUser();
            $operation = $this->pendingOperationFor($data);

            if ($this->confirmationRequired($data)) {
                if ($user->hasPasskey() && blank($data['password'] ?? null)) {
                    $livewire = $this->getLivewire();

                    assert($livewire instanceof LivewireComponent);

                    $livewire->dispatch(
                        'confirm-identity-ceremony',
                        componentId: $livewire->getId(),
                    );

                    $action->halt();
                }

                if (! $user->hasPassword()) {
                    // Nothing left to prove inline: no password field existed to
                    // validate, and a linked-provider offer (if any) is completed
                    // out of band, never by resubmitting this form.
                    $action->halt();
                }

                $recovery = (bool) ($data['use_recovery_code'] ?? false);

                try {
                    IdentityConfirmation::requireMfaProof(
                        $user,
                        $recovery ? null : ($data['code'] ?? null),
                        $recovery ? ($data['recovery_code'] ?? null) : null,
                    );
                } catch (ValidationException $exception) {
                    $field = $recovery ? 'recovery_code' : 'code';

                    // A password field only exists inside a mounted schema, so
                    // reaching here without one is impossible.
                    assert($schema instanceof Schema);

                    throw ValidationException::withMessages([
                        $schema->getStatePath().'.'.$field => $exception->errors()[$field],
                    ]);
                }

                IdentityConfirmation::confirmOperation($user, $operation);
            }

            throw_if(! $this->confirmedUsing instanceof Closure, LogicException::class, 'ConfirmIdentityAction: confirmedUsing callback is not set.');

            // The target comes from the grant that was actually proven, never
            // from a separately re-read Livewire argument: those are mutable
            // client-visible state and must not be trusted to still name the
            // same record the user proved fresh identity for.
            return $this->evaluate($this->confirmedUsing, [
                'action' => $action,
                'operationTarget' => $operation['target_id'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function confirmationRequired(array $data): bool
    {
        if (! $this->alwaysConfirm) {
            return ! IdentityConfirmation::confirmedRecently($this->within);
        }

        $attemptId = is_string($data['identity_attempt_id'] ?? null) ? $data['identity_attempt_id'] : null;

        if ($attemptId === null) {
            return true;
        }

        if ($this->operation !== null) {
            return ! AuthenticationSession::operationProven($attemptId);
        }

        $user = $this->confirmingUser();

        // A user with neither a password nor a passkey has only the linked
        // provider offer as proof, and that is a full-page round trip: it
        // necessarily finishes before this fresh attempt's own mount, every
        // time. Comparing against attemptStartedAt() would then reject every
        // provider-proven confirmation this population can ever produce.
        if (! $user->hasPassword() && ! $user->hasPasskey()) {
            return ! IdentityConfirmation::confirmedRecently();
        }

        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        return ! IdentityConfirmation::confirmedRecently() || $confirmedAt < AuthenticationSession::attemptStartedAt($attemptId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{operation: string, target_id: string|null}|null
     */
    private function pendingOperationFor(array $data): ?array
    {
        if ($this->operation === null) {
            return null;
        }

        $attemptId = is_string($data['identity_attempt_id'] ?? null) ? $data['identity_attempt_id'] : null;
        $pending = AuthenticationSession::pendingOperation();

        if ($attemptId === null || $pending === [] || $pending['id'] !== $attemptId) {
            return null;
        }

        return ['operation' => $pending['operation'], 'target_id' => $pending['target_id']];
    }

    /**
     * Pins a server-bound attempt id so re-confirmation is scoped to this
     * attempt, not the global freshness window. Persists across the
     * halt/re-entry cycle because Filament only evaluates default() once, at
     * mount, and the id then travels as ordinary (client-visible, not
     * client-writable) form state.
     *
     * @return array<int, Component>
     */
    private function attemptMarker(): array
    {
        if (! $this->alwaysConfirm) {
            return [];
        }

        return [
            Hidden::make('identity_attempt_id')->default(fn (): string => $this->createAttempt()),
        ];
    }

    private function createAttempt(): string
    {
        if ($this->operation === null) {
            return AuthenticationSession::beginAttempt();
        }

        $user = $this->confirmingUser();
        $target = $this->resolveTarget();
        $pending = AuthenticationSession::pendingOperation();

        // A provider-only account proves itself out of band, so a fresh attempt
        // here would discard the grant the OAuth round trip just proved.
        if ($pending !== [] && ! $user->hasPassword() && ! $user->hasPasskey()) {
            try {
                AuthenticationSession::requireOperation($user, $this->operation, $target);

                return $pending['id'];
            } catch (ValidationException) {
            }
        }

        return AuthenticationSession::startOperation($user, $this->operation, $target);
    }

    private function resolveTarget(): ?string
    {
        if ($this->target instanceof Closure) {
            return $this->evaluate($this->target);
        }

        return $this->target;
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
            ...$this->mfaFields($user, $usesPasswordField),
        ]));
    }

    /**
     * @return array<int, Component>
     */
    private function mfaFields(User $user, Closure $usesPasswordField): array
    {
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return [];
        }

        $usesCode = fn (Get $get): bool => $usesPasswordField($get) && ! (bool) $get('use_recovery_code');
        $usesRecoveryCode = fn (Get $get): bool => $usesPasswordField($get) && (bool) $get('use_recovery_code');

        return [
            Hidden::make('use_recovery_code')->default(false),
            OneTimeCodeInput::make('code')
                ->label(__('auth.mfa.code'))
                ->visible($usesCode)
                ->required($usesCode),
            TextInput::make('recovery_code')
                ->label(__('auth.mfa.recovery_code'))
                ->placeholder(__('auth.mfa.recovery_placeholder'))
                ->autocomplete('off')
                ->visible($usesRecoveryCode)
                ->required($usesRecoveryCode),
            Actions::make([
                Action::make('useRecoveryCode')
                    ->label(__('auth.mfa.use_recovery_code'))
                    ->link()
                    ->visible(fn (Get $get): bool => ! (bool) $get('use_recovery_code'))
                    ->action(function (Set $set): void {
                        $set('code', null);
                        $set('use_recovery_code', true);
                    }),
                Action::make('useAuthenticatorCode')
                    ->label(__('auth.mfa.use_code'))
                    ->link()
                    ->visible(fn (Get $get): bool => (bool) $get('use_recovery_code'))
                    ->action(function (Set $set): void {
                        $set('recovery_code', null);
                        $set('use_recovery_code', false);
                    }),
            ])->visible($usesPasswordField),
        ];
    }

    /**
     * Rendering this marks nothing confirmed; only completing the round trip does.
     * The descriptor is recorded on the click, not here, so merely opening and
     * abandoning the modal leaves nothing behind to re-open later.
     */
    private function providerConfirmationAction(): ?Action
    {
        $account = $this->linkedProviderAccount();

        if (! $account instanceof UserSocialAccount || ! $this->providerProofPending()) {
            return null;
        }

        $provider = $account->provider_name;

        return Action::make('confirmWithProvider')
            ->label(__('auth.confirm.continue_with_provider', ['provider' => ucfirst($provider)]))
            ->icon(SocialiteProvider::tryFrom($provider)?->icon())
            ->action(function () use ($provider): RedirectResponse {
                $this->rememberResumableAction();

                return redirect()->to(route('auth.socialite.confirm.redirect', ['provider' => $provider]));
            });
    }

    /**
     * False once the round trip returns proven, so the normal submit action
     * comes back and the operation can actually finish.
     */
    private function providerProofPending(): bool
    {
        if (! $this->linkedProviderAccount() instanceof UserSocialAccount) {
            return false;
        }

        if ($this->operation !== null) {
            $pending = AuthenticationSession::pendingOperation();

            return $pending === [] || ! $pending['proven'];
        }

        return ! IdentityConfirmation::confirmedRecently($this->alwaysConfirm ? null : $this->within);
    }

    private function rememberResumableAction(): void
    {
        if (! $this->resumable) {
            return;
        }

        $livewire = $this->getLivewire();

        assert($livewire instanceof LivewireComponent);

        AuthenticationSession::rememberResumableAction(
            $this->confirmingUser(),
            $livewire->getName(),
            $this->getName(),
            $this->getArguments(),
            $this->operation === null ? null : (AuthenticationSession::pendingOperation()['id'] ?? null),
        );
    }

    /**
     * Resolved several times per modal render, and each resolution costs a
     * passkey-exists plus a social-account query. The action lives one request.
     */
    private function linkedProviderAccount(): ?UserSocialAccount
    {
        if ($this->providerAccountResolved) {
            return $this->providerAccount;
        }

        $this->providerAccountResolved = true;
        $user = $this->confirmingUser();

        if (! $user->hasPassword() && ! $user->hasPasskey()) {
            $this->providerAccount = $user->socialAccounts()->first();
        }

        return $this->providerAccount;
    }

    private function confirmingUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
