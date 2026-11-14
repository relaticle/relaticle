<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

final readonly class SignupCeiling
{
    private const int HOUR_SECONDS = 3600;

    private const int DAY_SECONDS = 86400;

    public function isReached(string $ip): bool
    {
        $reached = array_keys(array_filter(
            $this->windows($ip),
            fn (array $window): bool => RateLimiter::tooManyAttempts($window[0], $window[1]),
        ));

        if ($reached === []) {
            return false;
        }

        Log::warning('Signup ceiling reached', ['limits' => $reached, 'ip' => $ip]);

        return true;
    }

    public function recordSignup(string $ip): void
    {
        foreach ($this->windows($ip) as [$key, , $seconds]) {
            RateLimiter::hit($key, $seconds);
        }
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    private function windows(string $ip): array
    {
        return array_filter([
            'ip_day' => ["signup:ip-day:{$ip}", (int) config('relaticle.signup.max_per_ip_per_day'), self::DAY_SECONDS],
            'site_hour' => ['signup:site-hour', (int) config('relaticle.signup.max_per_hour'), self::HOUR_SECONDS],
        ], fn (array $window): bool => $window[1] > 0);
    }
}
