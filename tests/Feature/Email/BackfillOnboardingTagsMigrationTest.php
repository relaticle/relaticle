<?php

declare(strict_types=1);

use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * The migration is a one-time repair for the 2026-08-26 Mailcoach TLS outage.
 * It runs against an empty database during the suite's own migrate, so its
 * behaviour is only observable by invoking it directly against seeded data.
 */
function backfillOnboardingTagsMigration(): object
{
    return require base_path('database/migrations/2026_08_27_120000_backfill_onboarding_tags_after_mailcoach_outage.php');
}

function teamCreatedAt(string $createdAt, ?OnboardingUseCase $useCase, ?OnboardingReferralSource $referral = null): User
{
    $owner = User::factory()->withTeam()->create();

    $owner->currentTeam->forceFill([
        'onboarding_use_case' => $useCase,
        'onboarding_referral_source' => $referral,
        'created_at' => $createdAt,
    ])->save();

    return $owner;
}

beforeEach(function (): void {
    Queue::fake([ModifySubscriberTagsJob::class]);
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
});

test('it re-dispatches onboarding tags for teams created during the outage', function (): void {
    $owner = teamCreatedAt('2026-08-26 03:30:00', OnboardingUseCase::Sales, OnboardingReferralSource::Google);

    backfillOnboardingTagsMigration()->up();

    Queue::assertPushed(ModifySubscriberTagsJob::class, fn (ModifySubscriberTagsJob $job): bool => invade($job)->userId === (string) $owner->id
        && invade($job)->tags === ['use-case:sales', 'referral:google']
        && invade($job)->action === TagAction::Add);
});

test('it ignores teams created outside the outage window', function (): void {
    teamCreatedAt('2026-08-25 12:00:00', OnboardingUseCase::Sales);
    teamCreatedAt('2026-08-26 09:00:00', OnboardingUseCase::Sales);

    backfillOnboardingTagsMigration()->up();

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('it skips teams that answered no onboarding questions', function (): void {
    teamCreatedAt('2026-08-26 03:30:00', null);

    backfillOnboardingTagsMigration()->up();

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});

test('it does nothing when subscriber sync is disabled', function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', false);

    teamCreatedAt('2026-08-26 03:30:00', OnboardingUseCase::Sales);

    backfillOnboardingTagsMigration()->up();

    Queue::assertNotPushed(ModifySubscriberTagsJob::class);
});
