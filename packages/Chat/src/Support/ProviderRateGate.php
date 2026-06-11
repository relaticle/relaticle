<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Per-provider stream-start limiter. One tenant's retry storm must not
 * stampede the provider into rate-limiting everyone else; jobs that cannot
 * get a slot are released back to the queue with jitter instead of starting
 * a doomed stream.
 */
final class ProviderRateGate
{
    public static function tryAcquire(?string $provider): bool
    {
        $limit = max(1, (int) config('chat.provider_starts_per_second', 8));

        try {
            return (bool) Redis::throttle('chat:provider:'.($provider ?? 'default'))
                ->allow($limit)
                ->every(1)
                ->block(0)
                ->then(static fn (): bool => true, static fn (): bool => false);
        } catch (LimiterTimeoutException) {
            return false;
        } catch (Throwable) {
            // Redis hiccup must never take chat down — fail open.
            return true;
        }
    }
}
