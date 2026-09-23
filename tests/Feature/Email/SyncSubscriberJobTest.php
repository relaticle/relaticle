<?php

declare(strict_types=1);

use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Jobs\Email\SyncSubscriberJob;
use App\Models\Company;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Email\SubscriberProfile;
use App\Support\Email\SubscriberProfileDeriver;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Models\AgentConversationMessage;
use Spatie\MailcoachSdk\Exceptions\InvalidData;
use Spatie\MailcoachSdk\Exceptions\RateLimited;
use Spatie\MailcoachSdk\Exceptions\ResourceNotFound;
use Spatie\MailcoachSdk\Facades\Mailcoach;
use Spatie\MailcoachSdk\Resources\Subscriber;

mutates(SyncSubscriberJob::class, SubscriberProfileDeriver::class, SubscriberProfile::class, AgentConversationMessage::class);

beforeEach(function (): void {
    Queue::fake([SyncSubscriberJob::class]);
    config([
        'mailcoach-sdk.api_token' => 'fake-token',
        'mailcoach-sdk.endpoint' => 'https://fake.mailcoach.test',
        'mailcoach-sdk.subscribers_list_id' => 'test-list-id',
        'mailcoach-sdk.enabled_subscribers_sync' => true,
    ]);
});

function syncSubscriberProfile(User $user): void
{
    new SyncSubscriberJob((string) $user->id)->handle(new SubscriberProfileDeriver);
}

test('creates a subscriber with the derived profile and stores uuid and hash', function (): void {
    $user = User::factory()->withWorkspace()->create(['name' => 'Ada Lovelace', 'email_verified_at' => now()]);

    Mailcoach::shouldReceive('findByEmail')
        ->once()
        ->with('test-list-id', $user->email)
        ->andReturnNull();

    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(fn (array $data): bool => $data['email'] === $user->email
            && $data['first_name'] === 'Ada'
            && $data['last_name'] === 'Lovelace'
            && $data['skip_confirmation'] === true
            && in_array('verified', $data['tags'], true)
            && in_array('signup-source:organic', $data['tags'], true)))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);

    expect($user->refresh())
        ->mailcoach_subscriber_uuid->toBe('new-uuid')
        ->subscriber_profile_hash->not->toBeNull();
});

test('resolves by stored uuid and carries an email change onto the same subscriber', function (): void {
    $user = User::factory()->withWorkspace()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => 'mc-uuid-1',
    ]);

    Mailcoach::shouldReceive('subscriber')
        ->once()
        ->with('mc-uuid-1')
        ->andReturn(new Subscriber(['uuid' => 'mc-uuid-1', 'email' => 'old@example.com', 'tags' => []]));

    Mailcoach::shouldReceive('updateSubscriber')
        ->once()
        ->with('mc-uuid-1', Mockery::on(fn (array $data): bool => $data['email'] === $user->email))
        ->andReturn(new Subscriber(['uuid' => 'mc-uuid-1', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);

    expect($user->refresh()->mailcoach_subscriber_uuid)->toBe('mc-uuid-1');
});

test('falls back to email lookup and adopts the found uuid when the stored uuid is gone', function (): void {
    $user = User::factory()->withWorkspace()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => 'gone-uuid',
    ]);

    Mailcoach::shouldReceive('subscriber')
        ->once()
        ->with('gone-uuid')
        ->andThrow(new ResourceNotFound);

    Mailcoach::shouldReceive('findByEmail')
        ->once()
        ->with('test-list-id', $user->email)
        ->andReturn(new Subscriber(['uuid' => 'found-uuid', 'email' => $user->email, 'tags' => []]));

    Mailcoach::shouldReceive('updateSubscriber')
        ->once()
        ->with('found-uuid', Mockery::type('array'))
        ->andReturn(new Subscriber(['uuid' => 'found-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);

    expect($user->refresh()->mailcoach_subscriber_uuid)->toBe('found-uuid');
});

test('creates a new subscriber when the email lookup returns a different address', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);

    Mailcoach::shouldReceive('findByEmail')
        ->once()
        ->with('test-list-id', $user->email)
        ->andReturn(new Subscriber(['uuid' => 'other-uuid', 'email' => "prefix{$user->email}", 'tags' => []]));

    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::type('array'))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);

    expect($user->refresh()->mailcoach_subscriber_uuid)->toBe('new-uuid');
});

test('preserves foreign tags and removes stale owned tags on update', function (): void {
    $user = User::factory()->withWorkspace()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => 'mc-uuid-1',
        'last_login_at' => now()->subDays(3),
    ]);

    Mailcoach::shouldReceive('subscriber')
        ->once()
        ->with('mc-uuid-1')
        ->andReturn(new Subscriber([
            'uuid' => 'mc-uuid-1',
            'email' => $user->email,
            'tags' => ['vip-customer', 'use-case:sales', 'dormant'],
        ]));

    Mailcoach::shouldReceive('updateSubscriber')
        ->once()
        ->with('mc-uuid-1', Mockery::on(fn (array $data): bool => in_array('vip-customer', $data['tags'], true)
            && in_array('active-7d', $data['tags'], true)
            && ! in_array('use-case:sales', $data['tags'], true)
            && ! in_array('dormant', $data['tags'], true)))
        ->andReturn(new Subscriber(['uuid' => 'mc-uuid-1', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
});

test('unions onboarding tags across all owned workspaces', function (): void {
    $user = User::factory()->withWorkspace(function ($workspace): void {
        $workspace->update([
            'onboarding_use_case' => OnboardingUseCase::Sales,
            'onboarding_referral_source' => OnboardingReferralSource::Google,
        ]);
    })->create(['email_verified_at' => now()]);

    $user->ownedWorkspaces()->create([
        'name' => 'Second Workspace',
        'slug' => 'second-workspace-'.$user->id,
        'personal_workspace' => false,
        'onboarding_use_case' => OnboardingUseCase::Recruiting,
        'onboarding_referral_source' => OnboardingReferralSource::LinkedIn,
    ]);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(fn (array $data): bool => in_array('use-case:sales', $data['tags'], true)
            && in_array('referral:google', $data['tags'], true)
            && in_array('use-case:recruiting', $data['tags'], true)
            && in_array('referral:linkedin', $data['tags'], true)))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
});

test('tags social login users with signup-source:social', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);
    UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider_name' => 'google']);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(fn (array $data): bool => in_array('signup-source:social', $data['tags'], true)
            && ! in_array('signup-source:organic', $data['tags'], true)))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
});

test('a social account linked after registration keeps signup-source:organic', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'google',
        'created_at' => $user->created_at->addDays(3),
    ]);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(fn (array $data): bool => in_array('signup-source:organic', $data['tags'], true)
            && ! in_array('signup-source:social', $data['tags'], true)))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
});

test('derives has-crm-data from records in any of the user workspaces', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);

    Company::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'account_owner_id' => $user->id,
    ]);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(fn (array $data): bool => in_array('has-crm-data', $data['tags'], true)))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
});

test('derives has-api-token and has-workspace-members from the database', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);
    $user->createToken('test-token', ['*']);
    $user->currentWorkspace->users()->attach(User::factory()->create(), ['role' => 'admin']);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(fn (array $data): bool => in_array('has-api-token', $data['tags'], true)
            && in_array('has-workspace-members', $data['tags'], true)))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
});

test('derives the recency bucket from last_login_at', function (?int $daysAgo, ?string $expectedTag): void {
    $user = User::factory()->withWorkspace()->create([
        'email_verified_at' => now(),
        'last_login_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
    ]);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(function (array $data) use ($expectedTag): bool {
            $recencyTags = array_intersect($data['tags'], ['active-7d', 'active-30d', 'dormant']);

            return $expectedTag === null
                ? $recencyTags === []
                : array_values($recencyTags) === [$expectedTag];
        }))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
})->with([
    [3, 'active-7d'],
    [20, 'active-30d'],
    [45, null],
    [90, 'dormant'],
    [null, null],
]);

test('skips the API entirely when the stored profile hash is current', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
    syncSubscriberProfile($user);
});

test('syncs when the hash is current but no uuid is stored', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);
    $profile = (new SubscriberProfileDeriver)->derive($user);
    $user->forceFill(['subscriber_profile_hash' => $profile->hash()])->save();

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);

    expect($user->refresh()->mailcoach_subscriber_uuid)->toBe('new-uuid');
});

test('makes no API calls when sync is disabled', function (): void {
    config(['mailcoach-sdk.enabled_subscribers_sync' => false]);

    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);

    Mailcoach::shouldReceive('findByEmail')->never();
    Mailcoach::shouldReceive('createSubscriber')->never();

    syncSubscriberProfile($user);

    expect($user->refresh()->subscriber_profile_hash)->toBeNull();
});

test('makes no API calls for an unverified user', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => null]);

    Mailcoach::shouldReceive('findByEmail')->never();
    Mailcoach::shouldReceive('createSubscriber')->never();

    syncSubscriberProfile($user);
});

test('makes no API calls for a deleted user', function (): void {
    Mailcoach::shouldReceive('findByEmail')->never();

    new SyncSubscriberJob('01hzzzzzzzzzzzzzzzzzzzzzzz')->handle(new SubscriberProfileDeriver);
});

test('releases with the retry-after delay when rate limited', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);

    Mailcoach::shouldReceive('findByEmail')
        ->once()
        ->andThrow(new RateLimited(120));

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once()->with(120);

    $job = new SyncSubscriberJob((string) $user->id);
    $job->setJob($queueJob);
    $job->handle(new SubscriberProfileDeriver);

    expect($user->refresh()->subscriber_profile_hash)->toBeNull();
});

function mailcoachRejectsTheEmail(): InvalidData
{
    return new InvalidData([
        'message' => 'The email field must be a valid email address.',
        'errors' => ['email' => ['The email field must be a valid email address.']],
    ]);
}

function syncExpectingPermanentFailure(User $user, InvalidData $exception): void
{
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('fail')->once()->with($exception);
    $queueJob->shouldReceive('release')->never();

    $job = new SyncSubscriberJob((string) $user->id);
    $job->setJob($queueJob);
    $job->handle(new SubscriberProfileDeriver);
}

test('fails without retrying and records the rejected profile when Mailcoach rejects an update', function (): void {
    $user = User::factory()->withWorkspace()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => 'mc-uuid-dead-domain',
        'subscriber_profile_hash' => 'hash-mailcoach-still-holds',
    ]);
    $exception = mailcoachRejectsTheEmail();

    Mailcoach::shouldReceive('subscriber')
        ->once()
        ->with('mc-uuid-dead-domain')
        ->andReturn(new Subscriber(['uuid' => 'mc-uuid-dead-domain', 'email' => $user->email, 'tags' => []]));
    Mailcoach::shouldReceive('updateSubscriber')->once()->andThrow($exception);

    syncExpectingPermanentFailure($user, $exception);

    $profile = (new SubscriberProfileDeriver)->derive($user->refresh());

    expect($user)
        ->mailcoach_subscriber_uuid->toBe('mc-uuid-dead-domain')
        ->subscriber_profile_hash->toBe('hash-mailcoach-still-holds')
        ->rejected_subscriber_profile_hash->toBe($profile->hash());
});

test('fails without retrying and records the rejected profile when Mailcoach rejects a create', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);
    $exception = mailcoachRejectsTheEmail();

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')->once()->andThrow($exception);

    syncExpectingPermanentFailure($user, $exception);

    $profile = (new SubscriberProfileDeriver)->derive($user->refresh());

    expect($user)
        ->mailcoach_subscriber_uuid->toBeNull()
        ->subscriber_profile_hash->toBeNull()
        ->rejected_subscriber_profile_hash->toBe($profile->hash());
});

test('does not re-offer a rejected profile until it changes', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);
    $user->forceFill(['rejected_subscriber_profile_hash' => (new SubscriberProfileDeriver)->derive($user)->hash()])->save();

    Mailcoach::shouldReceive('findByEmail')->never();
    Mailcoach::shouldReceive('createSubscriber')->never();

    syncSubscriberProfile($user);

    expect($user->refresh()->mailcoach_subscriber_uuid)->toBeNull();
});

test('a rejected profile is offered again once it changes, and success clears the rejection', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);
    $user->forceFill(['rejected_subscriber_profile_hash' => (new SubscriberProfileDeriver)->derive($user)->hash()])->save();

    $user->forceFill(['email' => 'renamed@example.com'])->save();

    Mailcoach::shouldReceive('findByEmail')->once()->with('test-list-id', 'renamed@example.com')->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => 'renamed@example.com', 'tags' => []]));

    syncSubscriberProfile($user);

    expect($user->refresh())
        ->mailcoach_subscriber_uuid->toBe('new-uuid')
        ->subscriber_profile_hash->not->toBeNull()
        ->rejected_subscriber_profile_hash->toBeNull();
});

function insertChatUserRow(User $user, MessageOrigin $origin): void
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'crm',
        'role' => 'user',
        'origin' => $origin->value,
        'content' => $origin->opener() ?? 'hello',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('a user who only saw the setup greeting is not tagged has-ai-usage', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);
    insertChatUserRow($user, MessageOrigin::Greeting);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(fn (array $data): bool => ! in_array('has-ai-usage', $data['tags'], true)))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
});

test('a user who typed a chat message is tagged has-ai-usage', function (): void {
    $user = User::factory()->withWorkspace()->create(['email_verified_at' => now()]);
    insertChatUserRow($user, MessageOrigin::Typed);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(fn (array $data): bool => in_array('has-ai-usage', $data['tags'], true)))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
});

test('stores the profile without any Mailcoach call when the user gave no marketing consent', function (): void {
    $user = User::factory()->withWorkspace()->withoutMarketingConsent()->create(['email_verified_at' => now()]);

    Mailcoach::shouldReceive('findByEmail')->never();
    Mailcoach::shouldReceive('createSubscriber')->never();

    syncSubscriberProfile($user);

    expect($user->refresh())
        ->mailcoach_subscriber_uuid->toBeNull()
        ->subscriber_profile_hash->not->toBeNull();
});

test('unsubscribes the stored subscriber when consent is withdrawn and keeps the uuid', function (): void {
    $user = User::factory()->withWorkspace()->withoutMarketingConsent()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => 'mc-uuid-1',
    ]);

    Mailcoach::shouldReceive('subscriber')
        ->once()
        ->with('mc-uuid-1')
        ->andReturn(new Subscriber(['uuid' => 'mc-uuid-1', 'email' => $user->email, 'tags' => [], 'unsubscribed_at' => null]));
    Mailcoach::shouldReceive('unsubscribeSubscriber')->once()->with('mc-uuid-1');
    Mailcoach::shouldReceive('updateSubscriber')->never();

    syncSubscriberProfile($user);

    expect($user->refresh())
        ->mailcoach_subscriber_uuid->toBe('mc-uuid-1')
        ->subscriber_profile_hash->not->toBeNull();
});

test('forgets a withdrawn subscriber Mailcoach no longer holds', function (): void {
    $user = User::factory()->withWorkspace()->withoutMarketingConsent()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => 'gone-uuid',
    ]);

    Mailcoach::shouldReceive('subscriber')->once()->with('gone-uuid')->andThrow(new ResourceNotFound);
    Mailcoach::shouldReceive('unsubscribeSubscriber')->never();

    syncSubscriberProfile($user);

    expect($user->refresh()->mailcoach_subscriber_uuid)->toBeNull();
});

test('resubscribes an unsubscribed subscriber when consent returns', function (): void {
    $user = User::factory()->withWorkspace()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => 'mc-uuid-1',
    ]);

    Mailcoach::shouldReceive('subscriber')
        ->once()
        ->with('mc-uuid-1')
        ->andReturn(new Subscriber(['uuid' => 'mc-uuid-1', 'email' => $user->email, 'tags' => [], 'unsubscribed_at' => '2026-09-01T00:00:00Z']));

    Mailcoach::shouldReceive('resubscribeSubscriber')->once()->with('mc-uuid-1');
    Mailcoach::shouldReceive('updateSubscriber')
        ->once()
        ->with('mc-uuid-1', Mockery::type('array'))
        ->andReturn(new Subscriber(['uuid' => 'mc-uuid-1', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
});

test('the reconcile sweep leaves a settled non-consenting user alone', function (): void {
    $user = User::factory()->withWorkspace()->withoutMarketingConsent()->create(['email_verified_at' => now()]);

    syncSubscriberProfile($user);

    expect(new SubscriberProfileDeriver()->derive($user->refresh())->needsSync($user))->toBeFalse();
});

test('skips the unsubscribe call when Mailcoach already holds the subscriber as unsubscribed', function (): void {
    $user = User::factory()->withWorkspace()->withoutMarketingConsent()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => 'mc-uuid-1',
    ]);

    Mailcoach::shouldReceive('subscriber')
        ->once()
        ->with('mc-uuid-1')
        ->andReturn(new Subscriber(['uuid' => 'mc-uuid-1', 'email' => $user->email, 'tags' => [], 'unsubscribed_at' => '2026-09-01T00:00:00Z']));
    Mailcoach::shouldReceive('unsubscribeSubscriber')->never();

    syncSubscriberProfile($user);

    expect($user->refresh()->subscriber_profile_hash)->not->toBeNull();
});

test('an email-link unsubscribe newer than the consent withdraws consent instead of resubscribing', function (): void {
    $user = User::factory()->withWorkspace()->create([
        'email_verified_at' => now(),
        'mailcoach_subscriber_uuid' => 'mc-uuid-1',
        'marketing_consent_at' => now()->subDays(10),
    ]);

    Mailcoach::shouldReceive('subscriber')
        ->once()
        ->with('mc-uuid-1')
        ->andReturn(new Subscriber(['uuid' => 'mc-uuid-1', 'email' => $user->email, 'tags' => [], 'unsubscribed_at' => now()->subDay()->toIso8601String()]));
    Mailcoach::shouldReceive('resubscribeSubscriber')->never();
    Mailcoach::shouldReceive('updateSubscriber')->never();

    syncSubscriberProfile($user);

    $user->refresh();

    expect($user->marketing_consent_at)->toBeNull()
        ->and(new SubscriberProfileDeriver()->derive($user)->needsSync($user))->toBeFalse();
});

test('a withdrawn profile ignores tag changes so reconcile leaves it alone', function (): void {
    $user = User::factory()->withWorkspace()->withoutMarketingConsent()->create(['email_verified_at' => now()]);

    syncSubscriberProfile($user);

    Company::factory()->create(['workspace_id' => $user->currentWorkspace->id, 'account_owner_id' => $user->id]);

    expect(new SubscriberProfileDeriver()->derive($user->refresh())->needsSync($user))->toBeFalse();
});
