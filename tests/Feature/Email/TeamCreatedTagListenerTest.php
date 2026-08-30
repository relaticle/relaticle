<?php

declare(strict_types=1);

use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Jobs\Email\SyncSubscriberJob;
use App\Listeners\Email\TeamCreatedTagListener;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Jetstream\Events\TeamCreated;

mutates(TeamCreatedTagListener::class);

beforeEach(function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
    Queue::fake([SyncSubscriberJob::class]);
});

test('dispatches a profile sync for the owner when the team has onboarding answers', function (): void {
    $owner = User::factory()->withTeam()->create();

    $team = $owner->currentTeam;
    $team->update([
        'onboarding_use_case' => OnboardingUseCase::Sales,
        'onboarding_referral_source' => OnboardingReferralSource::Google,
    ]);

    (new TeamCreatedTagListener)->handle(new TeamCreated($team->fresh()));

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $owner->id);
});

test('dispatches when only the use case is set', function (): void {
    $owner = User::factory()->withTeam()->create();

    $team = $owner->currentTeam;
    $team->update([
        'onboarding_use_case' => OnboardingUseCase::Marketing,
        'onboarding_referral_source' => null,
    ]);

    (new TeamCreatedTagListener)->handle(new TeamCreated($team->fresh()));

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $owner->id);
});

test('dispatches even when the owner has no mailcoach uuid yet', function (): void {
    $owner = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => null,
    ]);

    $team = $owner->currentTeam;
    $team->update([
        'onboarding_use_case' => OnboardingUseCase::Sales,
    ]);

    (new TeamCreatedTagListener)->handle(new TeamCreated($team->fresh()));

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $owner->id);
});

test('skips dispatch when the team has no onboarding answers', function (): void {
    $owner = User::factory()->withTeam()->create();

    $team = $owner->currentTeam;
    $team->update([
        'onboarding_use_case' => null,
        'onboarding_referral_source' => null,
    ]);

    (new TeamCreatedTagListener)->handle(new TeamCreated($team->fresh()));

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('dispatches for a second team with onboarding answers', function (): void {
    $owner = User::factory()->withTeam()->create();

    $secondTeam = $owner->ownedTeams()->create([
        'name' => 'Second Team',
        'slug' => 'second-team',
        'personal_team' => false,
        'onboarding_use_case' => OnboardingUseCase::Recruiting,
        'onboarding_referral_source' => OnboardingReferralSource::LinkedIn,
    ]);

    Queue::fake([SyncSubscriberJob::class]);

    (new TeamCreatedTagListener)->handle(new TeamCreated($secondTeam));

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $owner->id);
});
