<?php

declare(strict_types=1);

use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Listeners\Email\TeamCreatedTagListener;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Jetstream\Events\TeamCreated;

mutates(TeamCreatedTagListener::class);

beforeEach(function (): void {
    Queue::fake([ModifySubscriberTagsJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
});

test('dispatches use-case and referral tags when owner has uuid', function (): void {
    $owner = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'mc-uuid-owner',
    ]);

    $team = $owner->currentTeam;
    $team->update([
        'onboarding_use_case' => OnboardingUseCase::Sales,
        'onboarding_referral_source' => OnboardingReferralSource::Google,
    ]);

    (new TeamCreatedTagListener)->handle(new TeamCreated($team->fresh()));

    Queue::assertPushed(ModifySubscriberTagsJob::class, function (ModifySubscriberTagsJob $job): bool {
        return invade($job)->subscriberUuid === 'mc-uuid-owner'
            && invade($job)->tags === ['use-case:sales', 'referral:google']
            && invade($job)->action === TagAction::Add;
    });
});

test('dispatches only use-case tag when referral is null', function (): void {
    $owner = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'mc-uuid-owner',
    ]);

    $team = $owner->currentTeam;
    $team->update([
        'onboarding_use_case' => OnboardingUseCase::Marketing,
        'onboarding_referral_source' => null,
    ]);

    (new TeamCreatedTagListener)->handle(new TeamCreated($team->fresh()));

    Queue::assertPushed(ModifySubscriberTagsJob::class, function (ModifySubscriberTagsJob $job): bool {
        return invade($job)->tags === ['use-case:marketing'];
    });
});

test('does not dispatch tag job until owner has mailcoach uuid', function (): void {
    $owner = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => null,
    ]);

    $team = $owner->currentTeam;
    $team->update([
        'onboarding_use_case' => OnboardingUseCase::Sales,
    ]);

    (new TeamCreatedTagListener)->handle(new TeamCreated($team->fresh()));

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('dispatches tag job once uuid becomes available on retry', function (): void {
    $owner = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => null,
    ]);

    $team = $owner->currentTeam;
    $team->update([
        'onboarding_use_case' => OnboardingUseCase::Sales,
    ]);

    $listener = new TeamCreatedTagListener;
    $listener->handle(new TeamCreated($team->fresh()));
    Queue::assertNotPushed(ModifySubscriberTagsJob::class);

    $owner->forceFill(['mailcoach_subscriber_uuid' => 'mc-uuid-late'])->save();

    $listener->handle(new TeamCreated($team->fresh()));

    Queue::assertPushed(ModifySubscriberTagsJob::class, fn (ModifySubscriberTagsJob $job): bool => invade($job)->subscriberUuid === 'mc-uuid-late'
        && invade($job)->tags === ['use-case:sales']);
});

test('skips dispatch when there are no tags to apply (regardless of uuid state)', function (): void {
    $owner = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => null,
    ]);

    $team = $owner->currentTeam;
    $team->update([
        'onboarding_use_case' => null,
        'onboarding_referral_source' => null,
    ]);

    (new TeamCreatedTagListener)->handle(new TeamCreated($team->fresh()));

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('skips when sync is disabled', function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', false);

    $owner = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'mc-uuid-owner',
    ]);

    $team = $owner->currentTeam;
    $team->update([
        'onboarding_use_case' => OnboardingUseCase::Sales,
    ]);

    (new TeamCreatedTagListener)->handle(new TeamCreated($team->fresh()));

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('tags second team with different use case', function (): void {
    $owner = User::factory()->withTeam()->create([
        'mailcoach_subscriber_uuid' => 'mc-uuid-owner',
    ]);

    $secondTeam = $owner->ownedTeams()->create([
        'name' => 'Second Team',
        'slug' => 'second-team',
        'personal_team' => false,
        'onboarding_use_case' => OnboardingUseCase::Recruiting,
        'onboarding_referral_source' => OnboardingReferralSource::LinkedIn,
    ]);

    (new TeamCreatedTagListener)->handle(new TeamCreated($secondTeam));

    Queue::assertPushed(ModifySubscriberTagsJob::class, function (ModifySubscriberTagsJob $job): bool {
        return invade($job)->tags === ['use-case:recruiting', 'referral:linkedin']
            && invade($job)->action === TagAction::Add;
    });
});
