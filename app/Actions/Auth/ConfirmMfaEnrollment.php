<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Fortify;

final readonly class ConfirmMfaEnrollment
{
    public function __construct(private ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication) {}

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

            ($this->confirmTwoFactorAuthentication)($lockedUser, $code);
        });
    }
}
