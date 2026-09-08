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

        $this->release($seconds);

        return true;
    }

    protected function releaseIfProviderRateLimited(string $accountId, Throwable $exception): bool
    {
        $seconds = ProviderRateLimit::retryAfterSeconds($exception);

        if ($seconds === null) {
            return false;
        }

        ProviderRateLimit::trip($accountId, $seconds);
        $this->release($seconds);

        return true;
    }
}
