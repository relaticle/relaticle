<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

final readonly class EmailProbeLimiter
{
    private const int PER_MINUTE = 20;

    private const int PER_DAY = 300;

    public function hit(User $user): void
    {
        $minuteKey = "email-probe-minute:{$user->getKey()}";
        $dayKey = "email-probe-day:{$user->getKey()}";

        foreach ([$minuteKey => self::PER_MINUTE, $dayKey => self::PER_DAY] as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $retryAfter = RateLimiter::availableIn($key);

                throw new ThrottleRequestsException(
                    headers: ['Retry-After' => $retryAfter],
                );
            }
        }

        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($dayKey, 86400);
    }
}
