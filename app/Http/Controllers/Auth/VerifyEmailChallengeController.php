<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Validation\ValidationException;

/**
 * Placeholder for code redemption, which has no caller yet. Refuses every call
 * deterministically rather than leaving the route surface incomplete.
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
