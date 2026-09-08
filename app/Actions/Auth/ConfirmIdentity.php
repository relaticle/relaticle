<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AuthMethod;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\IdentityConfirmation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Scoped proof of identity for one sensitive operation. Verifies a fresh primary
 * proof (password, passkey, or an already-linked provider re-authenticated in
 * this same request) and, for enrolled MFA, a second factor, before confirming.
 *
 * A null attempt id serves only the generic 15-minute confirmation window
 * (Fortify's confirm-password page reached without a specific operation in
 * flight); a real attempt id must match the operation grant minted server-side
 * by AuthenticationSession::startOperation(), and that grant is consumed here,
 * never before.
 *
 * execute() returns the MFA continuation URL when a second factor is still
 * required, or an empty string once confirmation is complete; the redirect
 * destination in the success case is the caller's own concern (an HTTP
 * confirm-password endpoint has one, a Livewire modal does not). A passkey
 * ceremony that reaches this pending state completes through the sibling
 * CompleteIdentityMfa action instead, since the browser ceremony has no way to
 * collect an inline MFA code.
 */
final readonly class ConfirmIdentity
{
    public function __construct(private VerifyPasskey $verifyPasskey) {}

    /**
     * @param  array{password?: string, code?: string, recovery_code?: string, passkey_credential?: PublicKeyCredential, passkey_options?: PublicKeyCredentialRequestOptions, provider_verified?: bool}  $proof
     */
    public function execute(User $user, ?string $attemptId, AuthMethod $method, array $proof): string
    {
        $operation = $this->matchAttempt($user, $attemptId);

        $this->verifyPrimaryProof($user, $method, $proof);

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $code = $proof['code'] ?? null;
            $recoveryCode = $proof['recovery_code'] ?? null;

            if (blank($code) && blank($recoveryCode)) {
                IdentityConfirmation::markMfaPending($user, $operation['id'] ?? null);

                return route('identity.confirm.mfa');
            }

            IdentityConfirmation::requireMfaProof($user, $code, $recoveryCode);
        }

        IdentityConfirmation::confirmOperation($user, $operation !== null ? [
            'operation' => $operation['operation'],
            'target_id' => $operation['target_id'],
        ] : null);

        return '';
    }

    /**
     * @return array{id: string, operation: string, target_id: string|null}|null
     */
    private function matchAttempt(User $user, ?string $attemptId): ?array
    {
        if ($attemptId === null) {
            return null;
        }

        $pending = AuthenticationSession::pendingOperation();

        if ($pending === [] || ! hash_equals($pending['id'], $attemptId) || $pending['user_id'] !== (string) $user->getAuthIdentifier()) {
            throw ValidationException::withMessages([
                'identity' => [__('auth.confirm.required')],
            ]);
        }

        return ['id' => $pending['id'], 'operation' => $pending['operation'], 'target_id' => $pending['target_id']];
    }

    /**
     * @param  array{password?: string, code?: string, recovery_code?: string, passkey_credential?: PublicKeyCredential, passkey_options?: PublicKeyCredentialRequestOptions, provider_verified?: bool}  $proof
     */
    private function verifyPrimaryProof(User $user, AuthMethod $method, array $proof): void
    {
        match ($method) {
            AuthMethod::PASSWORD => $this->verifyPassword($user, $proof),
            AuthMethod::PASSKEY => $this->verifyPasskeyProof($user, $proof),
            AuthMethod::GOOGLE, AuthMethod::MICROSOFT => $this->verifyProviderProof($proof),
            AuthMethod::EMAIL, AuthMethod::SIGNUP, AuthMethod::REMEMBERED => throw new InvalidArgumentException("Unsupported identity-confirmation method: {$method->value}"),
        };
    }

    /**
     * Shares its throttle key with ConfirmIdentityAction's own password field
     * (confirm-identity:{id}), so an attacker cannot exhaust five attempts
     * against this raw HTTP endpoint and five more against the Filament form:
     * both draw down the same budget.
     *
     * @param  array{password?: string}  $proof
     */
    private function verifyPassword(User $user, array $proof): void
    {
        $password = $proof['password'] ?? null;
        $throttleKey = 'confirm-identity:'.$user->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'password' => [__('profile.form.password.throttled', ['seconds' => RateLimiter::availableIn($throttleKey)])],
            ]);
        }

        if (! is_string($password) || $password === '' || ! IdentityConfirmation::verifyPassword($user, $password)) {
            RateLimiter::hit($throttleKey, decaySeconds: 60);

            throw ValidationException::withMessages([
                'password' => [__('auth.password')],
            ]);
        }

        RateLimiter::clear($throttleKey);
    }

    /**
     * @param  array{passkey_credential?: PublicKeyCredential, passkey_options?: PublicKeyCredentialRequestOptions}  $proof
     */
    private function verifyPasskeyProof(User $user, array $proof): void
    {
        $credential = $proof['passkey_credential'] ?? null;
        $options = $proof['passkey_options'] ?? null;

        if (! $credential instanceof PublicKeyCredential || ! $options instanceof PublicKeyCredentialRequestOptions) {
            throw ValidationException::withMessages([
                'credential' => [__('auth.confirm.required')],
            ]);
        }

        try {
            ($this->verifyPasskey)($credential, $options, $user);
        } catch (WebauthnException) {
            throw InvalidPasskeyException::make('Unable to verify passkey. Please try again.');
        }
    }

    /**
     * The caller (the provider confirm callback) sets this only after validating
     * a freshly completed OAuth transaction against the user's own linked
     * association; it is never read from raw request input.
     *
     * @param  array{provider_verified?: bool}  $proof
     */
    private function verifyProviderProof(array $proof): void
    {
        if (($proof['provider_verified'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'identity' => [__('auth.confirm.required')],
            ]);
        }
    }
}
