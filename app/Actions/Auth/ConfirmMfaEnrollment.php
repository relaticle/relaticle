<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

/**
 * Turns a generated secret into an enforced second factor.
 *
 * The `manage_mfa` grant is spent upstream, when the secret is generated: the
 * grant fingerprint binds `two_factor_secret`, so writing one invalidates the
 * grant that authorized it. An abandoned enrolment still leaves the account
 * unprotected-but-unchanged, because nothing is enforced until this runs.
 */
final readonly class ConfirmMfaEnrollment
{
    public function execute(User $user): void
    {
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        event(new TwoFactorAuthenticationConfirmed($user));
    }
}
