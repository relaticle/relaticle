<?php

declare(strict_types=1);

use App\Console\Commands\ReconcileSubscribersCommand;
use App\Jobs\Email\SyncSubscriberJob;
use App\Models\User;
use App\Support\Email\SubscriberProfileDeriver;
use Illuminate\Support\Facades\Queue;

mutates(ReconcileSubscribersCommand::class);

beforeEach(function (): void {
    Queue::fake([SyncSubscriberJob::class]);
    config([
        'mailcoach-sdk.enabled_subscribers_sync' => true,
        'mailcoach-sdk.subscribers_list_id' => 'test-list-id',
    ]);
});

function userWithCurrentProfileHash(): User
{
    $user = User::factory()->withTeam()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => 'mc-uuid-current',
    ]);

    $hash = (new SubscriberProfileDeriver)->derive($user)->hash();
    $user->forceFill(['subscriber_profile_hash' => $hash])->save();

    return $user;
}

test('dispatches sync jobs only for users whose derived profile drifted', function (): void {
    $current = userWithCurrentProfileHash();

    $drifted = User::factory()->withTeam()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => 'mc-uuid-drifted',
        'subscriber_profile_hash' => 'stale-hash',
    ]);

    $this->artisan('subscribers:reconcile')
        ->expectsOutputToContain('Dispatched 1 sync jobs.')
        ->assertSuccessful();

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $drifted->id);
    Queue::assertNotPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $current->id);
});

test('dispatches for verified users who never received a subscriber uuid', function (): void {
    $user = User::factory()->withTeam()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => null,
    ]);

    $this->artisan('subscribers:reconcile')->assertSuccessful();

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $user->id);
});

test('does not re-dispatch a user whose unchanged profile Mailcoach already rejected', function (): void {
    $rejected = User::factory()->withTeam()->create(['email_verified_at' => now()]);
    $rejected->forceFill(['rejected_subscriber_profile_hash' => (new SubscriberProfileDeriver)->derive($rejected)->hash()])->save();

    $this->artisan('subscribers:reconcile')
        ->expectsOutputToContain('Dispatched 0 sync jobs.')
        ->expectsOutputToContain('Skipped 1 profiles Mailcoach already rejected.')
        ->assertSuccessful();

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('reports the rejected skip count on a dry run too', function (): void {
    $rejected = User::factory()->withTeam()->create(['email_verified_at' => now()]);
    $rejected->forceFill(['rejected_subscriber_profile_hash' => (new SubscriberProfileDeriver)->derive($rejected)->hash()])->save();

    $this->artisan('subscribers:reconcile', ['--dry-run' => true])
        ->expectsOutputToContain('Would sync 0 users.')
        ->expectsOutputToContain('Skipped 1 profiles Mailcoach already rejected.')
        ->assertSuccessful();
});

test('stays silent about rejected profiles when there are none', function (): void {
    User::factory()->withTeam()->create(['email_verified_at' => now()]);

    $this->artisan('subscribers:reconcile')
        ->doesntExpectOutputToContain('Mailcoach already rejected')
        ->assertSuccessful();
});

test('re-dispatches a rejected user once the derived profile differs from the rejected one', function (): void {
    $user = User::factory()->withTeam()->create([
        'email_verified_at' => now(),
        'rejected_subscriber_profile_hash' => 'hash-of-the-old-dead-address',
    ]);

    $this->artisan('subscribers:reconcile')
        ->expectsOutputToContain('Dispatched 1 sync jobs.')
        ->assertSuccessful();

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $user->id);
});

test('skips unverified users', function (): void {
    User::factory()->withTeam()->create(['email_verified_at' => null]);

    $this->artisan('subscribers:reconcile')
        ->expectsOutputToContain('Dispatched 0 sync jobs.')
        ->assertSuccessful();

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('dry-run lists drifted users without dispatching', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);

    $this->artisan('subscribers:reconcile --dry-run')
        ->expectsOutputToContain("Would sync {$user->email}")
        ->expectsOutputToContain('Would sync 1 users.')
        ->assertSuccessful();

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('limit caps the number of dispatched jobs', function (): void {
    User::factory()->withTeam()->count(3)->create(['email_verified_at' => now()]);

    $this->artisan('subscribers:reconcile --limit=2')
        ->expectsOutputToContain('Dispatched 2 sync jobs.')
        ->assertSuccessful();

    Queue::assertPushed(SyncSubscriberJob::class, 2);
});

test('does nothing when sync is disabled', function (): void {
    config(['mailcoach-sdk.enabled_subscribers_sync' => false]);

    User::factory()->withTeam()->create(['email_verified_at' => now()]);

    $this->artisan('subscribers:reconcile')
        ->expectsOutputToContain('Mailcoach subscriber sync is disabled.')
        ->assertSuccessful();

    Queue::assertNotPushed(SyncSubscriberJob::class);
});
