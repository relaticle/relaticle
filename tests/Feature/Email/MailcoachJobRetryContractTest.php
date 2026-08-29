<?php

declare(strict_types=1);

use App\Data\SubscriberData;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Jobs\Email\SyncRecencyBucketJob;
use App\Jobs\Email\SyncSubscriberJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;

mutates(SyncSubscriberJob::class, SyncRecencyBucketJob::class, ModifySubscriberTagsJob::class);

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
test('mailcoach jobs retry on attempts alone, with nothing able to swallow the exception', function (object $job, int $tries, string $backoff): void {
    $queue = Queue::connection('sync');

    // array_merge() mirrors CallQueuedHandler::dispatchThroughMiddleware() exactly:
    // anything in that pipeline can catch and release instead of rethrowing.
    $middleware = array_merge(
        method_exists($job, 'middleware') ? $job->middleware() : [],
        $job->middleware ?? [],
    );

    expect($queue->getJobExpiration($job))->toBeNull()
        ->and($queue->getJobTries($job))->toBe($tries)
        ->and($queue->getJobBackoff($job))->toBe($backoff)
        ->and($middleware)->toBe([]);
})->with([
    'sync subscriber' => [
        fn (): SyncSubscriberJob => new SyncSubscriberJob(SubscriberData::from(['email' => 'a@b.test'])),
        5,
        '60,300,900,3600',
    ],
    'sync recency bucket' => [
        fn (): SyncRecencyBucketJob => new SyncRecencyBucketJob('user-1', 'sub-1', null, 'active-7d'),
        5,
        '60,300,900,3600',
    ],
    'modify subscriber tags' => [
        fn (): ModifySubscriberTagsJob => new ModifySubscriberTagsJob('user-1', ['use-case:sales'], TagAction::Add),
        6,
        '60,300,900,1800,3600',
    ],
]);

test('the unique subscriber sync bounds its lock so a killed worker cannot block an email forever', function (): void {
    $job = new SyncSubscriberJob(SubscriberData::from(['email' => 'a@b.test']));

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBeGreaterThan(array_sum([60, 300, 900, 3600]));
});
