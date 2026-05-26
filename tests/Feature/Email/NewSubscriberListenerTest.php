<?php

declare(strict_types=1);

use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Enums\SubscriberTagEnum;
use App\Jobs\Email\SyncSubscriberJob;
use App\Listeners\Email\NewSubscriberListener;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Queue;

mutates(NewSubscriberListener::class);

beforeEach(function (): void {
    Queue::fake([SyncSubscriberJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
    config()->set('mailcoach-sdk.subscribers_list_id', 'test-list-id');
});

test('dispatches SyncSubscriberJob for verified organic user', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);

    event(new Verified($user));

    Queue::assertPushed(SyncSubscriberJob::class, function (SyncSubscriberJob $job) use ($user): bool {
        $data = invade($job)->data;

        return $data->email === $user->email
            && in_array(SubscriberTagEnum::Verified->value, $data->tags, true)
            && in_array(SubscriberTagEnum::SignupSourceOrganic->value, $data->tags, true)
            && $data->user_id === (string) $user->id;
    });
});

test('tags social login users with signup-source:social', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);
    UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider_name' => 'google']);

    event(new Verified($user));

    Queue::assertPushed(SyncSubscriberJob::class, function (SyncSubscriberJob $job): bool {
        $data = invade($job)->data;

        return in_array(SubscriberTagEnum::SignupSourceSocial->value, $data->tags, true);
    });
});

test('tags GitHub social login users with signup-source:social', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);
    UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider_name' => 'github']);

    event(new Verified($user));

    Queue::assertPushed(SyncSubscriberJob::class, function (SyncSubscriberJob $job): bool {
        return in_array(SubscriberTagEnum::SignupSourceSocial->value, invade($job)->data->tags, true);
    });
});

test('includes use-case tag from first team onboarding data', function (): void {
    $user = User::factory()->withTeam(function ($team): void {
        $team->update(['onboarding_use_case' => OnboardingUseCase::Sales]);
    })->create(['email_verified_at' => now()]);

    event(new Verified($user));

    Queue::assertPushed(SyncSubscriberJob::class, function (SyncSubscriberJob $job): bool {
        return in_array('use-case:sales', invade($job)->data->tags, true);
    });
});

test('includes referral tag from first team onboarding data', function (): void {
    $user = User::factory()->withTeam(function ($team): void {
        $team->update(['onboarding_referral_source' => OnboardingReferralSource::Google]);
    })->create(['email_verified_at' => now()]);

    event(new Verified($user));

    Queue::assertPushed(SyncSubscriberJob::class, function (SyncSubscriberJob $job): bool {
        return in_array('referral:google', invade($job)->data->tags, true);
    });
});

test('includes both use-case and referral tags when both are set', function (): void {
    $user = User::factory()->withTeam(function ($team): void {
        $team->update([
            'onboarding_use_case' => OnboardingUseCase::Recruiting,
            'onboarding_referral_source' => OnboardingReferralSource::LinkedIn,
        ]);
    })->create(['email_verified_at' => now()]);

    event(new Verified($user));

    Queue::assertPushed(SyncSubscriberJob::class, function (SyncSubscriberJob $job): bool {
        $tags = invade($job)->data->tags;

        return in_array('use-case:recruiting', $tags, true)
            && in_array('referral:linkedin', $tags, true);
    });
});

test('omits onboarding tags when team has no onboarding data', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);

    event(new Verified($user));

    Queue::assertPushed(SyncSubscriberJob::class, function (SyncSubscriberJob $job): bool {
        $tags = invade($job)->data->tags;

        $hasOnboardingTag = collect($tags)->contains(
            fn (string $tag): bool => str_starts_with($tag, 'use-case:') || str_starts_with($tag, 'referral:')
        );

        return ! $hasOnboardingTag;
    });
});

test('does not dispatch any job when sync is disabled', function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', false);

    $user = User::factory()->withTeam()->create(['email_verified_at' => now()]);

    event(new Verified($user));

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('does not dispatch any job when email is not verified', function (): void {
    $user = User::factory()->withTeam()->create(['email_verified_at' => null]);

    event(new Verified($user));

    Queue::assertNotPushed(SyncSubscriberJob::class);
});
