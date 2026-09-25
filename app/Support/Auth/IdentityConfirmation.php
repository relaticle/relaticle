<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Throwable;

/**
 * Uniform identity-confirmation gate, mirroring Laravel's RequirePassword model.
 *
 * A passkey ceremony (vendor passkey.confirm endpoint), a fresh linked-provider
 * authentication, or a password entry all refresh auth.password_confirmed_at;
 * callers gate on freshness. Every path must still prove current possession of
 * one of those methods: a session alone is never enough.
 */
final readonly class IdentityConfirmation
{
    private const string MFA_PENDING_KEY = 'auth.confirm.mfa_pending';

    private const int MFA_PENDING_LIFETIME_MINUTES = 5;

    public static function satisfied(): bool
    {
        return self::confirmedRecently();
    }

    public static function confirmedRecently(?int $maxAge = null): bool
    {
        $maxAge ??= Config::integer('auth.confirmation_window', 900);
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        return (time() - $confirmedAt) < $maxAge;
    }

    public static function markConfirmed(): void
    {
        session()->put('auth.password_confirmed_at', time());
    }

    /**
     * Mark the operation grant proven by this confirmation (if any) and mark the
     * session confirmed. Proof alone never spends the grant: it only makes the
     * grant eligible for AuthenticationSession::requireOperation()/
     * consumeOperation() at the point the mutation actually happens, which may
     * be later in this same request or a wholly separate one.
     *
     * @param  array{operation: string, target_id: string|null}|null  $operation
     */
    public static function confirmOperation(User $user, ?array $operation): void
    {
        if ($operation !== null) {
            AuthenticationSession::proveOperation($user, $operation['operation'], $operation['target_id']);
        }

        self::markConfirmed();
    }

    public static function verifyPassword(User $user, string $password): bool
    {
        return Hash::check($password, (string) $user->password);
    }

    public static function markMfaPending(User $user, ?string $operationGrantId): void
    {
        session()->put(self::MFA_PENDING_KEY, [
            'user_id' => (string) $user->getAuthIdentifier(),
            'expires_at' => now()->addMinutes(self::MFA_PENDING_LIFETIME_MINUTES)->getTimestamp(),
            'operation_grant_id' => $operationGrantId,
        ]);
    }

    public static function mfaPendingFor(User $user): bool
    {
        return self::mfaPending($user) !== null;
    }

    /**
     * The operation grant id bound when the MFA-pending marker was set, or null
     * if that confirmation had no operation in flight (the generic window) or
     * the marker is missing, foreign, or expired.
     */
    public static function mfaPendingOperationGrantId(User $user): ?string
    {
        $pending = self::mfaPending($user);

        return $pending !== null && is_string($pending['operation_grant_id'] ?? null)
            ? $pending['operation_grant_id']
            : null;
    }

    public static function clearMfaPending(): void
    {
        session()->forget(self::MFA_PENDING_KEY);
    }

    /**
     * @return array{user_id: string, expires_at: int, operation_grant_id: string|null}|null
     */
    private static function mfaPending(User $user): ?array
    {
        $pending = session()->get(self::MFA_PENDING_KEY);

        if (
            ! is_array($pending)
            || ($pending['user_id'] ?? null) !== (string) $user->getAuthIdentifier()
            || ! is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] <= now()->getTimestamp()
            || ! array_key_exists('operation_grant_id', $pending)
            || (! is_string($pending['operation_grant_id']) && $pending['operation_grant_id'] !== null)
        ) {
            return null;
        }

        return [
            'user_id' => $pending['user_id'],
            'expires_at' => $pending['expires_at'],
            'operation_grant_id' => $pending['operation_grant_id'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public static function requireMfaProof(User $user, ?string $code, ?string $recoveryCode): void
    {
        if (! self::verifyMfaCode($user, $code, $recoveryCode)) {
            throw ValidationException::withMessages([
                blank($recoveryCode) ? 'code' : 'recovery_code' => [__(blank($recoveryCode) ? 'auth.mfa.code_invalid' : 'auth.mfa.recovery_invalid')],
            ]);
        }
    }

    /**
     * Verify enrolled MFA as part of an identity confirmation, independent of the
     * login MFA challenge. Mirrors VerifyMfa's lock and recovery-code discipline
     * without sharing it: the two flows serve different purposes and must not
     * let a proof minted for one silently satisfy the other.
     */
    public static function verifyMfaCode(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return true;
        }

        $throttleKey = 'confirm-mfa:'.$user->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            return false;
        }

        $valid = $recoveryCode !== null && $recoveryCode !== ''
            ? self::consumeRecoveryCode($user, $recoveryCode)
            : self::verifyTotp($user, $code);

        if (! $valid) {
            RateLimiter::hit($throttleKey, decaySeconds: 60);

            return false;
        }

        RateLimiter::clear($throttleKey);

        return true;
    }

    private static function verifyTotp(User $user, ?string $code): bool
    {
        if (! is_string($code) || preg_match('/^\d{6}$/D', $code) !== 1) {
            return false;
        }

        try {
            return Cache::lock('mfa-verify:'.$user->getAuthIdentifier(), 10)->block(5, function () use ($user, $code): bool {
                $currentUser = User::query()->find($user->getKey());

                if (! $currentUser instanceof User || ! $currentUser->hasEnabledTwoFactorAuthentication()) {
                    return false;
                }

                try {
                    $secret = Fortify::currentEncrypter()->decrypt((string) $currentUser->two_factor_secret);

                    return resolve(TwoFactorAuthenticationProvider::class)->verify($secret, $code);
                } catch (Throwable) {
                    return false;
                }
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private static function consumeRecoveryCode(User $user, string $recoveryCode): bool
    {
        return DB::transaction(function () use ($user, $recoveryCode): bool {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $lockedUser instanceof User) {
                return false;
            }

            try {
                $storedCode = collect($lockedUser->recoveryCodes())
                    ->first(fn (string $candidate): bool => hash_equals($candidate, $recoveryCode));
            } catch (Throwable) {
                return false;
            }

            if (! is_string($storedCode)) {
                return false;
            }

            $lockedUser->replaceRecoveryCode($storedCode);

            return true;
        });
    }
}
