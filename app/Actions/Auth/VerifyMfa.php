<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;
use Throwable;

final readonly class VerifyMfa
{
    public function __construct(private CompleteAuthentication $completeAuthentication) {}

    public function execute(?string $code, ?string $recoveryCode): string
    {
        $pending = AuthenticationSession::pending();
        $user = $pending === [] ? null : User::query()->find($pending['user_id']);

        if (! $user instanceof User || ! AuthenticationSession::isValidFor($user) || ! $user->hasEnabledTwoFactorAuthentication()) {
            AuthenticationSession::clear();

            throw $this->invalid($recoveryCode, __('auth.mfa.expired'));
        }

        if (! $this->consumeValidFactor($user, $code, $recoveryCode)) {
            event(new TwoFactorAuthenticationFailed($user));

            throw $this->invalid($recoveryCode, __(blank($recoveryCode) ? 'auth.mfa.code_invalid' : 'auth.mfa.recovery_invalid'));
        }

        event(new ValidTwoFactorAuthenticationCodeProvided($user));

        return $this->completeAuthentication->execute();
    }

    private function consumeValidFactor(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if (($code !== null && $code !== '') && ($recoveryCode !== null && $recoveryCode !== '')) {
            return false;
        }

        if ($recoveryCode !== null && $recoveryCode !== '') {
            return $this->consumeRecoveryCode($user, $recoveryCode);
        }

        if (! is_string($code) || preg_match('/^\d{6}$/D', $code) !== 1) {
            return false;
        }

        return $this->verifyCode($user, $code);
    }

    private function verifyCode(User $user, string $code): bool
    {
        try {
            return Cache::lock('mfa-verify:'.$user->getAuthIdentifier(), 10)->block(5, function () use ($user, $code): bool {
                $currentUser = User::query()->find($user->getKey());

                if (! $currentUser instanceof User || ! AuthenticationSession::isValidFor($currentUser) || ! $currentUser->hasEnabledTwoFactorAuthentication()) {
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

    private function consumeRecoveryCode(User $user, string $recoveryCode): bool
    {
        return DB::transaction(function () use ($user, $recoveryCode): bool {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $lockedUser instanceof User || ! AuthenticationSession::isValidFor($lockedUser) || ! $lockedUser->hasEnabledTwoFactorAuthentication()) {
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

    private function invalid(?string $recoveryCode, string $message): ValidationException
    {
        $key = $recoveryCode !== null && $recoveryCode !== '' ? 'recovery_code' : 'code';

        return ValidationException::withMessages([$key => [$message]]);
    }
}
