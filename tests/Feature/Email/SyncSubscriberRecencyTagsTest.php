<?php

declare(strict_types=1);

use App\Console\Commands\SyncSubscriberRecencyTagsCommand;
use App\Enums\SubscriberTagEnum;
use App\Jobs\Email\SyncRecencyBucketJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

mutates(SyncSubscriberRecencyTagsCommand::class);

beforeEach(function () {
    Queue::fake([SyncRecencyBucketJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
});

test('user who logged in today gets active-7d tag', function () {
    $this->travelTo(now());

    User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'uuid-active',
        'last_login_at' => now()->subHours(2),
        'subscriber_recency_bucket' => null,
    ]);

    $this->artisan(SyncSubscriberRecencyTagsCommand::class)->assertSuccessful();

    Queue::assertPushed(SyncRecencyBucketJob::class, function (SyncRecencyBucketJob $job) {
        return invade($job)->subscriberUuid === 'uuid-active'
            && invade($job)->oldBucket === null
            && invade($job)->newBucket === SubscriberTagEnum::Active7d->value;
    });
});

test('user who logged in 15 days ago gets active-30d tag', function () {
    $this->travelTo(now());

    User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'uuid-semi',
        'last_login_at' => now()->subDays(15),
        'subscriber_recency_bucket' => null,
    ]);

    $this->artisan(SyncSubscriberRecencyTagsCommand::class)->assertSuccessful();

    Queue::assertPushed(SyncRecencyBucketJob::class, function (SyncRecencyBucketJob $job) {
        return invade($job)->subscriberUuid === 'uuid-semi'
            && invade($job)->oldBucket === null
            && invade($job)->newBucket === SubscriberTagEnum::Active30d->value;
    });
});

test('user who never logged in is not tagged', function () {
    User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'uuid-never',
        'last_login_at' => null,
        'subscriber_recency_bucket' => null,
    ]);

    $this->artisan(SyncSubscriberRecencyTagsCommand::class)->assertSuccessful();

    Queue::assertNotPushed(SyncRecencyBucketJob::class);
});

test('user who has not logged in for 60+ days gets dormant tag', function () {
    $this->travelTo(now());

    User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'uuid-dormant',
        'last_login_at' => now()->subDays(90),
        'subscriber_recency_bucket' => null,
    ]);

    $this->artisan(SyncSubscriberRecencyTagsCommand::class)->assertSuccessful();

    Queue::assertPushed(SyncRecencyBucketJob::class, function (SyncRecencyBucketJob $job) {
        return invade($job)->subscriberUuid === 'uuid-dormant'
            && invade($job)->newBucket === SubscriberTagEnum::Dormant->value;
    });
});

test('user whose bucket has not changed is skipped', function () {
    User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'uuid-stable',
        'last_login_at' => now()->subDays(2),
        'subscriber_recency_bucket' => SubscriberTagEnum::Active7d->value,
    ]);

    $this->artisan(SyncSubscriberRecencyTagsCommand::class)->assertSuccessful();

    Queue::assertNotPushed(SyncRecencyBucketJob::class);
});

test('bucket transition dispatches job with old and new bucket', function () {
    $this->travelTo(now());

    User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'uuid-transition',
        'last_login_at' => now()->subDays(10),
        'subscriber_recency_bucket' => SubscriberTagEnum::Active7d->value,
    ]);

    $this->artisan(SyncSubscriberRecencyTagsCommand::class)->assertSuccessful();

    Queue::assertPushed(SyncRecencyBucketJob::class, function (SyncRecencyBucketJob $job) {
        return invade($job)->subscriberUuid === 'uuid-transition'
            && invade($job)->oldBucket === SubscriberTagEnum::Active7d->value
            && invade($job)->newBucket === SubscriberTagEnum::Active30d->value;
    });
});

test('user without mailcoach uuid is skipped', function () {
    User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => null,
        'last_login_at' => now(),
        'subscriber_recency_bucket' => null,
    ]);

    $this->artisan(SyncSubscriberRecencyTagsCommand::class)->assertSuccessful();

    Queue::assertNotPushed(SyncRecencyBucketJob::class);
});

test('bucket is only persisted when sync job completes', function () {
    $this->travelTo(now());

    $user = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'uuid-persist',
        'last_login_at' => now()->subDays(2),
        'subscriber_recency_bucket' => null,
    ]);

    $this->artisan(SyncSubscriberRecencyTagsCommand::class)->assertSuccessful();

    expect($user->refresh()->subscriber_recency_bucket)->toBeNull();
});

test('user in 30-60 day transition window gets no recency tag', function () {
    $this->travelTo(now());

    User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'uuid-limbo',
        'last_login_at' => now()->subDays(45),
        'subscriber_recency_bucket' => SubscriberTagEnum::Active30d->value,
    ]);

    $this->artisan(SyncSubscriberRecencyTagsCommand::class)->assertSuccessful();

    Queue::assertPushed(SyncRecencyBucketJob::class, function (SyncRecencyBucketJob $job) {
        return invade($job)->oldBucket === SubscriberTagEnum::Active30d->value
            && invade($job)->newBucket === null;
    });
});
