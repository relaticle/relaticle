<?php

declare(strict_types=1);

use App\Jobs\Email\SyncSubscriberJob;
use App\Listeners\Email\NewSubscriberListener;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Queue;

mutates(NewSubscriberListener::class);

beforeEach(function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
    Queue::fake([SyncSubscriberJob::class]);
});

test('dispatches a profile sync when a user verifies their email', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);

    event(new Verified($user));

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $user->id);
});
