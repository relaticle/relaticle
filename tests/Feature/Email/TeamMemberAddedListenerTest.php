<?php

declare(strict_types=1);

use App\Enums\SubscriberTagEnum;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Listeners\Email\TeamMemberAddedListener;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Jetstream\Events\TeamMemberAdded;

mutates(TeamMemberAddedListener::class);

beforeEach(function (): void {
    Queue::fake([ModifySubscriberTagsJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
});

test('dispatches has-team-members tag for team owner when member is added', function (): void {
    $owner = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'mc-uuid-owner',
    ]);

    $member = User::factory()->create();

    event(new TeamMemberAdded($owner->currentTeam, $member));

    Queue::assertPushed(ModifySubscriberTagsJob::class, function (ModifySubscriberTagsJob $job): bool {
        return invade($job)->subscriberUuid === 'mc-uuid-owner'
            && invade($job)->tags === [SubscriberTagEnum::HasTeamMembers->value]
            && invade($job)->action === TagAction::Add;
    });
});

test('does not dispatch when owner has no mailcoach uuid', function (): void {
    $owner = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => null,
    ]);

    $member = User::factory()->create();

    event(new TeamMemberAdded($owner->currentTeam, $member));

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('does not dispatch when sync is disabled', function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', false);

    $owner = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'mc-uuid-owner',
    ]);

    $member = User::factory()->create();

    event(new TeamMemberAdded($owner->currentTeam, $member));

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});
