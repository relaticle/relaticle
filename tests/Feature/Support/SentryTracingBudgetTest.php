<?php

declare(strict_types=1);

use Relaticle\EmailIntegration\Jobs\EnsureCalendarPushChannelJob;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Sentry\Laravel\Jobs\Middleware\SentryTracesSampleRate;

it('leaves blade view rendering out of traces', function (): void {
    expect(config('sentry.tracing.views'))->toBeFalse();
});

it('downsamples the traces of the jobs every mailbox runs every few minutes', function (IncrementalEmailSyncJob|IncrementalCalendarSyncJob|EnsureCalendarPushChannelJob $job): void {
    expect(collect($job->middleware())->contains(fn (object $item): bool => $item instanceof SentryTracesSampleRate))->toBeTrue();
})->with([
    'mailbox sync' => fn (): IncrementalEmailSyncJob => new IncrementalEmailSyncJob(ConnectedAccount::factory()->make()),
    'calendar sync' => fn (): IncrementalCalendarSyncJob => new IncrementalCalendarSyncJob(ConnectedAccount::factory()->make()),
    'calendar push channel' => fn (): EnsureCalendarPushChannelJob => new EnsureCalendarPushChannelJob(ConnectedAccount::factory()->make()),
]);
