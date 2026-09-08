<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Validation\ValidationException;

/**
 * Registered now so the route surface is complete; redemption itself is a
 * later task's action. Every call refuses deterministically until then.
 */
final readonly class VerifyEmailChallengeController
{
    public function __invoke(): never
    {
        throw ValidationException::withMessages([
            'code' => [__('auth.email_code.verify_unavailable')],
        ]);
    }
}
