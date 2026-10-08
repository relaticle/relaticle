<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs\Middleware;

use Closure;
use Illuminate\Queue\Jobs\SyncJob;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Jobs\EnsureCalendarPushChannelJob;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Services\ProviderRateLimit;
use Throwable;

final class HandlesProviderFailures
{
    use DetectsAuthErrors;

    private const int AUTH_RETRY_SECONDS = 60;

    public function handle(
        IncrementalEmailSyncJob|InitialEmailSyncJob|IncrementalCalendarSyncJob|InitialCalendarSyncJob|EnsureCalendarPushChannelJob $job,
        Closure $next,
    ): void {
        try {
            $next($job);
        } catch (Throwable $exception) {
            // A sync queue cannot run a released job again, so the failure stays loud there.
            throw_if($job->job instanceof SyncJob, $exception);

            $outageSeconds = ProviderRateLimit::outageSeconds($exception);

            if ($outageSeconds !== null) {
                ProviderRateLimit::trip((string) $job->connectedAccount->getKey(), $outageSeconds);
                $job->release($outageSeconds);

                return;
            }

            throw_unless($this->isAuthError($exception), $exception);

            // A token can lapse mid-run and the next attempt refreshes it. A second rejection is final.
            if ($job->attempts() === 1) {
                $job->release(self::AUTH_RETRY_SECONDS);

                return;
            }

            $job->fail($exception);
        }
    }
}
