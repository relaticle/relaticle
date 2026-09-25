<?php

declare(strict_types=1);

use App\Jobs\Email\SyncSubscriberJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;

mutates(SyncSubscriberJob::class);

/**
 * A Mailcoach TLS outage on 2026-08-26 turned 25 real failures into 1,046
 * Sentry events. Two causes, both pinned here:
 *
 * 1. retryUntil() silently voids #[Tries] - Worker::markJobAsFailedIfAlready
 *    ExceedsMaxAttempts returns early whenever a retry deadline is still in
 *    the future, so maxTries is never evaluated.
 * 2. A ThrottlesExceptions middleware catches the exception and releases the
 *    job instead of rethrowing, so Worker::handleJobException never runs.
 *    That makes #[Backoff] and #[MaxExceptions] unreachable, and its
 *    ->report() fires once per retry.
 *
 * These assert the exact payload values the queue Worker reads.
 */
test('the subscriber sync retries on attempts alone, with nothing able to swallow the exception', function (): void {
    $job = new SyncSubscriberJob('user-1');
    $queue = Queue::connection('sync');

    // array_merge() mirrors CallQueuedHandler::dispatchThroughMiddleware() exactly:
    // anything in that pipeline can catch and release instead of rethrowing.
    $middleware = array_merge(
        method_exists($job, 'middleware') ? $job->middleware() : [],
        $job->middleware ?? [],
    );

    expect($queue->getJobExpiration($job))->toBeNull()
        ->and($queue->getJobTries($job))->toBe(5)
        ->and($queue->getJobBackoff($job))->toBe('60,300,900,3600')
        ->and($middleware)->toBe([]);
});

test('the unique subscriber sync bounds its lock so a killed worker cannot block a user forever', function (): void {
    $job = new SyncSubscriberJob('user-1');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('user-1')
        ->and($job->uniqueFor)->toBeGreaterThan(array_sum([60, 300, 900, 3600]));
});
