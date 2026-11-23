<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs\Concerns;

use Relaticle\EmailIntegration\Services\ProviderRateLimit;
use Throwable;

trait ReleasesOnProviderRateLimit
{
    protected function releaseIfProviderCoolingDown(string $accountId): bool
    {
        $seconds = ProviderRateLimit::remainingSeconds($accountId);

        if ($seconds === null) {
            return false;
        }

        $this->release($seconds + $this->providerRateLimitJitter($seconds));

        return true;
    }

    protected function releaseIfProviderRateLimited(string $accountId, Throwable $exception): bool
    {
        $seconds = ProviderRateLimit::retryAfterSeconds($exception);

        if ($seconds === null) {
            return false;
        }

        ProviderRateLimit::trip($accountId, $seconds);

        $this->release($seconds + $this->providerRateLimitJitter($seconds));

        return true;
    }

    protected function releaseIfProviderUnavailable(string $accountId, Throwable $exception): bool
    {
        $seconds = ProviderRateLimit::outageSeconds($exception);

        if ($seconds === null) {
            return false;
        }

        ProviderRateLimit::trip($accountId, $seconds);

        $this->release($seconds + $this->providerRateLimitJitter($seconds));

        return true;
    }

    /**
     * A mailbox's parked jobs wake spread over a window, not all at once when
     * the cooldown ends.
     */
    private function providerRateLimitJitter(int $seconds): int
    {
        return random_int(0, max(1, intdiv($seconds, 2)));
    }
}
