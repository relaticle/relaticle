<?php

declare(strict_types=1);

use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\User;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Spatie\MailcoachSdk\Facades\Mailcoach;

mutates(ModifySubscriberTagsJob::class);

beforeEach(function () {
    config(['mailcoach-sdk.api_token' => 'fake-token', 'mailcoach-sdk.endpoint' => 'https://fake.mailcoach.test']);
});

function waitingTagJob(User $user, int $attempt, int $expectedDelay): ModifySubscriberTagsJob
{
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);
    $queueJob->shouldReceive('release')->once()->with($expectedDelay);

    $job = new ModifySubscriberTagsJob((string) $user->id, ['use-case:sales'], TagAction::Add);
    $job->setJob($queueJob);

    return $job;
}

test('it calls the Mailcoach add tags endpoint', function () {
    $user = User::factory()->create(['mailcoach_subscriber_uuid' => 'test-uuid-123']);

    Mailcoach::shouldReceive('post')
        ->once()
        ->with('subscribers/test-uuid-123/tags', ['tags' => ['has-crm-data']])
        ->andReturnNull();

    (new ModifySubscriberTagsJob((string) $user->id, ['has-crm-data'], TagAction::Add))->handle();
});

test('it sends multiple tags in a single add call', function () {
    $user = User::factory()->create(['mailcoach_subscriber_uuid' => 'test-uuid-456']);

    Mailcoach::shouldReceive('post')
        ->once()
        ->with('subscribers/test-uuid-456/tags', ['tags' => ['active-7d', 'has-crm-data']])
        ->andReturnNull();

    (new ModifySubscriberTagsJob((string) $user->id, ['active-7d', 'has-crm-data'], TagAction::Add))->handle();
});

test('it calls the Mailcoach delete tags endpoint for remove action', function () {
    $user = User::factory()->create(['mailcoach_subscriber_uuid' => 'test-uuid-123']);

    Mailcoach::shouldReceive('delete')
        ->once()
        ->with('subscribers/test-uuid-123/tags', ['tags' => ['dormant']])
        ->andReturnNull();

    (new ModifySubscriberTagsJob((string) $user->id, ['dormant'], TagAction::Remove))->handle();
});

test('it waits instead of tagging, backing off further on each attempt, while the uuid has not landed', function (int $attempt, int $expectedDelay) {
    $user = User::factory()->create(['mailcoach_subscriber_uuid' => null]);

    Mailcoach::shouldReceive('post')->never();
    Mailcoach::shouldReceive('delete')->never();

    waitingTagJob($user, $attempt, $expectedDelay)->handle();
})->with([
    [1, 60],
    [2, 300],
    [3, 900],
    [4, 1800],
    [5, 3600],
    [6, 3600],
]);

test('it tags a user whose uuid arrived while the job was waiting', function () {
    $user = User::factory()->create(['mailcoach_subscriber_uuid' => null]);

    $job = waitingTagJob($user, 1, 60);
    $job->handle();

    $user->forceFill(['mailcoach_subscriber_uuid' => 'mc-uuid-late'])->save();

    Mailcoach::shouldReceive('post')
        ->once()
        ->with('subscribers/mc-uuid-late/tags', ['tags' => ['use-case:sales']])
        ->andReturnNull();

    $job->handle();
});
