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
use Illuminate\Support\Facades\Queue;
use Spatie\MailcoachSdk\Exceptions\InvalidData;
use Spatie\MailcoachSdk\Exceptions\RateLimited;
use Spatie\MailcoachSdk\Exceptions\ResourceNotFound;
use Spatie\MailcoachSdk\Facades\Mailcoach;
use Spatie\MailcoachSdk\Resources\Subscriber;

mutates(SyncSubscriberJob::class, SubscriberProfileDeriver::class, SubscriberProfile::class);

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
    $user = User::factory()->withTeam()->create(['name' => 'Ada Lovelace', 'email_verified_at' => now()]);

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
    $user = User::factory()->withTeam()->create([
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
    $user = User::factory()->withTeam()->create([
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
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);

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
    $user = User::factory()->withTeam()->create([
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

test('unions onboarding tags across all owned teams', function (): void {
    $user = User::factory()->withTeam(function ($team): void {
        $team->update([
            'onboarding_use_case' => OnboardingUseCase::Sales,
            'onboarding_referral_source' => OnboardingReferralSource::Google,
        ]);
    })->create(['email_verified_at' => now()]);

    $user->ownedTeams()->create([
        'name' => 'Second Team',
        'slug' => 'second-team-'.$user->id,
        'personal_team' => false,
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
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);
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
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);
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

test('derives has-crm-data from records in any of the user teams', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);

    Company::factory()->create([
        'team_id' => $user->currentTeam->id,
        'account_owner_id' => $user->id,
    ]);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(fn (array $data): bool => in_array('has-crm-data', $data['tags'], true)))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
});

test('derives has-api-token and has-team-members from the database', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);
    $user->createToken('test-token', ['*']);
    $user->currentTeam->users()->attach(User::factory()->create(), ['role' => 'admin']);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->with('test-list-id', Mockery::on(fn (array $data): bool => in_array('has-api-token', $data['tags'], true)
            && in_array('has-team-members', $data['tags'], true)))
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
});

test('derives the recency bucket from last_login_at', function (?int $daysAgo, ?string $expectedTag): void {
    $user = User::factory()->withTeam()->create([
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
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);

    Mailcoach::shouldReceive('findByEmail')->once()->andReturnNull();
    Mailcoach::shouldReceive('createSubscriber')
        ->once()
        ->andReturn(new Subscriber(['uuid' => 'new-uuid', 'email' => $user->email, 'tags' => []]));

    syncSubscriberProfile($user);
    syncSubscriberProfile($user);
});

test('syncs when the hash is current but no uuid is stored', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);
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

    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);

    Mailcoach::shouldReceive('findByEmail')->never();
    Mailcoach::shouldReceive('createSubscriber')->never();

    syncSubscriberProfile($user);

    expect($user->refresh()->subscriber_profile_hash)->toBeNull();
});

test('makes no API calls for an unverified user', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => null]);

    Mailcoach::shouldReceive('findByEmail')->never();
    Mailcoach::shouldReceive('createSubscriber')->never();

    syncSubscriberProfile($user);
});

test('makes no API calls for a deleted user', function (): void {
    Mailcoach::shouldReceive('findByEmail')->never();

    new SyncSubscriberJob('01hzzzzzzzzzzzzzzzzzzzzzzz')->handle(new SubscriberProfileDeriver);
});

test('releases with the retry-after delay when rate limited', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);

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
    $user = User::factory()->withTeam()->create([
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
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);
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
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);
    $user->forceFill(['rejected_subscriber_profile_hash' => (new SubscriberProfileDeriver)->derive($user)->hash()])->save();

    Mailcoach::shouldReceive('findByEmail')->never();
    Mailcoach::shouldReceive('createSubscriber')->never();

    syncSubscriberProfile($user);

    expect($user->refresh()->mailcoach_subscriber_uuid)->toBeNull();
});

test('a rejected profile is offered again once it changes, and success clears the rejection', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);
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
