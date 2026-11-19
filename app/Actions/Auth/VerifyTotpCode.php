<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

final readonly class VerifyTotpCode
{
    public function __construct(private Google2FA $google2fa) {}

    public function execute(User $user, string $secret, string $code): bool
    {
        $window = config('fortify-options.two-factor-authentication.window');

        $this->google2fa->setWindow(is_int($window) ? $window : 1);

        $step = $this->google2fa->verifyKeyNewer($secret, $code, $user->two_factor_last_used_step ?? 0);

        if (! is_int($step)) {
            return false;
        }

        $user->forceFill(['two_factor_last_used_step' => $step])->save();

        return true;
    }
}
