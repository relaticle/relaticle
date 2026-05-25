<?php

declare(strict_types=1);

use App\Data\SubscriberData;
use App\Jobs\Email\SyncSubscriberJob;
use App\Models\User;
use Spatie\MailcoachSdk\Facades\Mailcoach;
use Spatie\MailcoachSdk\Resources\Subscriber;

mutates(SyncSubscriberJob::class);

beforeEach(function () {
    config([
        'mailcoach-sdk.api_token' => 'fake-token',
        'mailcoach-sdk.endpoint' => 'https://fake.mailcoach.test',
        'mailcoach-sdk.subscribers_list_id' => 'test-list-id',
    ]);
});

test('it creates a new subscriber when none exists', function () {
    $user = User::factory()->withTeam()->create();

    $fakeSubscriber = new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]);

    Mailcoach::shouldReceive('findByEmail')
        ->once()
        ->with('test-list-id', $user->email)
        ->andReturnNull();

    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(fn (array $data) => $data['email'] === $user->email && $data['skip_confirmation'] === true))
        ->andReturn($fakeSubscriber);

    $data = SubscriberData::from([
        'email' => $user->email,
        'first_name' => $user->name,
        'last_name' => $user->name,
        'tags' => ['verified'],
        'skip_confirmation' => true,
        'user_id' => (string) $user->id,
    ]);

    (new SyncSubscriberJob($data))->handle();

    expect($user->refresh()->mailcoach_subscriber_uuid)->toBe('new-uuid');
});

test('it updates an existing subscriber and merges tags', function () {
    $user = User::factory()->withTeam()->create();

    $existingSubscriber = new Subscriber([
        'uuid' => 'existing-uuid',
        'email' => $user->email,
        'tags' => ['old-tag'],
    ]);

    Mailcoach::shouldReceive('findByEmail')
        ->once()
        ->with('test-list-id', $user->email)
        ->andReturn($existingSubscriber);

    Mailcoach::shouldReceive('updateSubscriber')
        ->once()
        ->with('existing-uuid', Mockery::on(fn (array $data) => in_array('old-tag', $data['tags']) && in_array('verified', $data['tags'])))
        ->andReturn($existingSubscriber);

    $data = SubscriberData::from([
        'email' => $user->email,
        'tags' => ['verified'],
        'user_id' => (string) $user->id,
    ]);

    (new SyncSubscriberJob($data))->handle();

    expect($user->refresh()->mailcoach_subscriber_uuid)->toBe('existing-uuid');
});

test('it stores the subscriber uuid on the user', function () {
    $user = User::factory()->withTeam()->create(['mailcoach_subscriber_uuid' => null]);

    $fakeSubscriber = new Subscriber(['uuid' => 'stored-uuid', 'email' => $user->email, 'tags' => []]);

    Mailcoach::shouldReceive('findByEmail')->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')->andReturn($fakeSubscriber);

    $data = SubscriberData::from([
        'email' => $user->email,
        'first_name' => $user->name,
        'last_name' => $user->name,
        'tags' => ['verified'],
        'skip_confirmation' => true,
        'user_id' => (string) $user->id,
    ]);

    (new SyncSubscriberJob($data))->handle();

    expect($user->refresh()->mailcoach_subscriber_uuid)->toBe('stored-uuid');
});

test('it does not overwrite existing subscriber uuid', function () {
    $user = User::factory()->withTeam()->create(['mailcoach_subscriber_uuid' => 'original-uuid']);

    $fakeSubscriber = new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]);

    Mailcoach::shouldReceive('findByEmail')->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')->andReturn($fakeSubscriber);

    $data = SubscriberData::from([
        'email' => $user->email,
        'tags' => ['verified'],
        'skip_confirmation' => true,
        'user_id' => (string) $user->id,
    ]);

    (new SyncSubscriberJob($data))->handle();

    expect($user->refresh()->mailcoach_subscriber_uuid)->toBe('original-uuid');
});
