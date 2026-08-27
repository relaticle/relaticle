<?php

declare(strict_types=1);

use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    Queue::fake([ModifySubscriberTagsJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
});

test('it re-dispatches onboarding tags for teams created inside the window', function (): void {
    $owner = User::factory()->withTeam()->create();

    $owner->currentTeam->forceFill([
        'onboarding_use_case' => OnboardingUseCase::Sales,
        'onboarding_referral_source' => OnboardingReferralSource::Google,
        'created_at' => '2026-08-26 03:30:00',
    ])->save();

    artisan('subscribers:backfill-onboarding-tags', [
        '--since' => '2026-08-26T02:00',
        '--until' => '2026-08-26T07:00',
    ])->assertSuccessful();

    Queue::assertPushed(ModifySubscriberTagsJob::class, fn (ModifySubscriberTagsJob $job): bool => invade($job)->userId === (string) $owner->id
        && invade($job)->tags === ['use-case:sales', 'referral:google']
        && invade($job)->action === TagAction::Add);
});

test('it ignores teams created outside the window', function (): void {
    $owner = User::factory()->withTeam()->create();

    $owner->currentTeam->forceFill([
        'onboarding_use_case' => OnboardingUseCase::Sales,
        'created_at' => '2026-08-25 12:00:00',
    ])->save();

    artisan('subscribers:backfill-onboarding-tags', [
        '--since' => '2026-08-26T02:00',
        '--until' => '2026-08-26T07:00',
    ])->assertSuccessful();

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('it skips teams with no onboarding answers', function (): void {
    $owner = User::factory()->withTeam()->create();

    $owner->currentTeam->forceFill([
        'onboarding_use_case' => null,
        'onboarding_referral_source' => null,
        'created_at' => '2026-08-26 03:30:00',
    ])->save();

    artisan('subscribers:backfill-onboarding-tags', [
        '--since' => '2026-08-26T02:00',
        '--until' => '2026-08-26T07:00',
    ])->assertSuccessful();

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('it refuses to run when subscriber sync is disabled', function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', false);

    artisan('subscribers:backfill-onboarding-tags', [
        '--since' => '2026-08-26T02:00',
        '--until' => '2026-08-26T07:00',
    ])->assertFailed();

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('it rejects an inverted window', function (): void {
    artisan('subscribers:backfill-onboarding-tags', [
        '--since' => '2026-08-26T07:00',
        '--until' => '2026-08-26T02:00',
    ])->assertFailed();

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});
