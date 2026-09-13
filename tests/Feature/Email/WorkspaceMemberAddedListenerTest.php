<?php

declare(strict_types=1);

use App\Jobs\Email\SyncSubscriberJob;
use App\Listeners\Email\WorkspaceMemberAddedListener;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Jetstream\Events\TeamMemberAdded;

mutates(WorkspaceMemberAddedListener::class);

beforeEach(function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
    Queue::fake([SyncSubscriberJob::class]);
});

test('dispatches a profile sync for both the owner and the new member', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $member = User::factory()->create();

    event(new TeamMemberAdded($owner->currentWorkspace, $member));

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $owner->id);
    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $member->id);
});
