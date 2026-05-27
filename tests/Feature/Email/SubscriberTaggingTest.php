<?php

declare(strict_types=1);

use App\Enums\SubscriberTagEnum;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake([ModifySubscriberTagsJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
});

test('creating the first company dispatches has-crm-data tag job', function (): void {
    $user = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'mc-uuid-123',
    ]);

    $this->actingAs($user);

    Company::factory()->create([
        'team_id' => $user->currentTeam->id,
        'account_owner_id' => $user->id,
    ]);

    Queue::assertPushed(ModifySubscriberTagsJob::class, function (ModifySubscriberTagsJob $job): bool {
        return invade($job)->subscriberUuid === 'mc-uuid-123'
            && invade($job)->tags === [SubscriberTagEnum::HasCrmData->value]
            && invade($job)->action === TagAction::Add;
    });
});

test('creating a second company does not dispatch the tag job again', function (): void {
    $user = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'mc-uuid-123',
    ]);

    $this->actingAs($user);

    Company::factory()->create([
        'team_id' => $user->currentTeam->id,
        'account_owner_id' => $user->id,
    ]);

    Queue::fake([ModifySubscriberTagsJob::class]);

    Company::factory()->create([
        'team_id' => $user->currentTeam->id,
        'account_owner_id' => $user->id,
    ]);

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('user without mailcoach uuid does not dispatch tag job', function (): void {
    $user = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => null,
    ]);

    $this->actingAs($user);

    Company::factory()->create([
        'team_id' => $user->currentTeam->id,
        'account_owner_id' => $user->id,
    ]);

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('creating company when sync is disabled does not dispatch tag job', function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', false);

    $user = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'mc-uuid-123',
    ]);

    $this->actingAs($user);

    Company::factory()->create([
        'team_id' => $user->currentTeam->id,
        'account_owner_id' => $user->id,
    ]);

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('creating first personal access token dispatches has-api-token tag job', function (): void {
    $user = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'mc-uuid-456',
    ]);

    $user->createToken('test-token', ['*']);

    Queue::assertPushed(ModifySubscriberTagsJob::class, function (ModifySubscriberTagsJob $job): bool {
        return invade($job)->subscriberUuid === 'mc-uuid-456'
            && invade($job)->tags === [SubscriberTagEnum::HasApiToken->value]
            && invade($job)->action === TagAction::Add;
    });
});

test('creating a second personal access token does not dispatch tag job again', function (): void {
    $user = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'mc-uuid-456',
    ]);

    $user->createToken('first-token', ['*']);

    Queue::fake([ModifySubscriberTagsJob::class]);

    $user->createToken('second-token', ['*']);

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});
