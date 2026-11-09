<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs\Middleware;

use Closure;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Queue\Jobs\SyncJob;
use Relaticle\EmailIntegration\Actions\CompleteMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Actions\StopCalendarPushChannelAction;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\SyncErrorReason;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Jobs\EnsureCalendarPushChannelJob;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
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

            $disabledService = $this->disabledService($exception);

            if ($disabledService === 'calendar') {
                $this->disableCalendar($job);

                return;
            }

            if ($disabledService === 'mail') {
                $this->failMailDisabled($job, $exception);

                return;
            }

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

    private function disabledService(Throwable $exception): ?string
    {
        if (! $exception instanceof GoogleServiceException) {
            return null;
        }

        foreach ($exception->getErrors() ?? [] as $error) {
            $reason = $error['reason'] ?? null;

            if ($reason === 'notACalendarUser') {
                return 'calendar';
            }

            if ($reason === 'failedPrecondition' && stripos((string) ($error['message'] ?? ''), 'Mail service not enabled') !== false) {
                return 'mail';
            }
        }

        return null;
    }

    private function disableCalendar(
        IncrementalEmailSyncJob|InitialEmailSyncJob|IncrementalCalendarSyncJob|InitialCalendarSyncJob|EnsureCalendarPushChannelJob $job,
    ): void {
        $account = $job->connectedAccount->fresh();

        if ($account instanceof ConnectedAccount) {
            resolve(StopCalendarPushChannelAction::class)->execute($account);
            $account->disableCalendar();

            resolve(MailboxHistoryImportService::class)->completeCalendarImport($account, succeeded: false);
            resolve(CompleteMailboxHistoryImportAction::class)->executeForAccount($account);
        }

        $job->delete();
    }

    private function failMailDisabled(
        IncrementalEmailSyncJob|InitialEmailSyncJob|IncrementalCalendarSyncJob|InitialCalendarSyncJob|EnsureCalendarPushChannelJob $job,
        Throwable $exception,
    ): void {
        $job->fail($exception);

        $job->connectedAccount->fresh()?->update([
            'status' => EmailAccountStatus::ERROR,
            'last_error' => SyncErrorReason::GoogleMailDisabled->value,
        ]);
    }
}
