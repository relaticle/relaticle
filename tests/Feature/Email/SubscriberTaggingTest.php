<?php

declare(strict_types=1);

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake([SyncSubscriberJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
});

test('creating the first company dispatches a profile sync', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user);

    Company::factory()->create([
        'team_id' => $user->currentTeam->id,
        'account_owner_id' => $user->id,
    ]);

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $user->id);
});

test('creating a second company does not dispatch a sync again', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user);

    Company::factory()->create([
        'team_id' => $user->currentTeam->id,
        'account_owner_id' => $user->id,
    ]);

    Queue::fake([SyncSubscriberJob::class]);

    Company::factory()->create([
        'team_id' => $user->currentTeam->id,
        'account_owner_id' => $user->id,
    ]);

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('dispatches even when the user has no mailcoach uuid yet', function (): void {
    $user = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => null,
    ]);

    $this->actingAs($user);

    Company::factory()->create([
        'team_id' => $user->currentTeam->id,
        'account_owner_id' => $user->id,
    ]);

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $user->id);
});

test('creating company when sync is disabled does not dispatch', function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', false);

    $user = User::factory()->withTeam()->create();

    $this->actingAs($user);

    Company::factory()->create([
        'team_id' => $user->currentTeam->id,
        'account_owner_id' => $user->id,
    ]);

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('creating the first personal access token dispatches a profile sync', function (): void {
    $user = User::factory()->withTeam()->create();

    $user->createToken('test-token', ['*']);

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $user->id);
});

test('creating a second personal access token does not dispatch again', function (): void {
    $user = User::factory()->withTeam()->create();

    $user->createToken('first-token', ['*']);

    Queue::fake([SyncSubscriberJob::class]);

    $user->createToken('second-token', ['*']);

    Queue::assertNotPushed(SyncSubscriberJob::class);
});
