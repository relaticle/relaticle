<?php

declare(strict_types=1);

use App\Data\SubscriberData;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Jobs\Email\SyncRecencyBucketJob;
use App\Jobs\Email\SyncSubscriberJob;
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
test('mailcoach jobs are bounded by attempts, never by a retry deadline', function (object $job, int $tries, string $backoff): void {
    $queue = Queue::connection('sync');

    expect($queue->getJobExpiration($job))->toBeNull()
        ->and($queue->getJobTries($job))->toBe($tries)
        ->and($queue->getJobBackoff($job))->toBe($backoff);
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

test('mailcoach jobs register no middleware that could swallow the exception', function (object $job): void {
    // The exact list CallQueuedHandler::dispatchThroughMiddleware() pipes the job through.
    // Anything in it can catch and release, which is what made #[Backoff] and
    // #[MaxExceptions] unreachable and reported one Sentry event per retry.
    $pipeline = array_merge(
        method_exists($job, 'middleware') ? $job->middleware() : [],
        $job->middleware ?? [],
    );

    expect($pipeline)->toBe([]);
})->with([
    'sync subscriber' => fn (): SyncSubscriberJob => new SyncSubscriberJob(SubscriberData::from(['email' => 'a@b.test'])),
    'sync recency bucket' => fn (): SyncRecencyBucketJob => new SyncRecencyBucketJob('user-1', 'sub-1', null, 'active-7d'),
    'modify subscriber tags' => fn (): ModifySubscriberTagsJob => new ModifySubscriberTagsJob('user-1', ['use-case:sales'], TagAction::Add),
]);
