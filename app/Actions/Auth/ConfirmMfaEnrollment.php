<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Fortify;

final readonly class ConfirmMfaEnrollment
{
    public function __construct(private VerifyTotpCode $verifyTotpCode) {}

    public function execute(User $user, string $secret, string $code): void
    {
        DB::transaction(function () use ($user, $secret, $code): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (
                $lockedUser->hasEnabledTwoFactorAuthentication()
                || blank($lockedUser->two_factor_secret)
                || ! hash_equals(Fortify::currentEncrypter()->decrypt($lockedUser->two_factor_secret), $secret)
            ) {
                throw ValidationException::withMessages(['code' => __('profile.sections.mfa.code_invalid')]);
            }

            $lockedUser->two_factor_last_used_step = null;

            if (! $this->verifyTotpCode->execute($lockedUser, $secret, $code)) {
                throw ValidationException::withMessages(['code' => __('profile.sections.mfa.code_invalid')]);
            }

            $lockedUser->forceFill(['two_factor_confirmed_at' => now()])->save();

            event(new TwoFactorAuthenticationConfirmed($lockedUser));
        });
    }
}
