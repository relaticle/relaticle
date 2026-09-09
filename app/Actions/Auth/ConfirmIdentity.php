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
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

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

        if ($method !== AuthMethod::PASSKEY && $user->hasEnabledTwoFactorAuthentication()) {
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

        ($this->verifyPasskey)($credential, $options, $user);
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
