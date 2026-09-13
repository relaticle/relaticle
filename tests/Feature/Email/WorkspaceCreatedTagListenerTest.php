<?php

declare(strict_types=1);

use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Events\WorkspaceCreated;
use App\Jobs\Email\SyncSubscriberJob;
use App\Listeners\Email\WorkspaceCreatedTagListener;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

mutates(WorkspaceCreatedTagListener::class);

beforeEach(function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
    Queue::fake([SyncSubscriberJob::class]);
});

test('dispatches a profile sync for the owner when the workspace has onboarding answers', function (): void {
    $owner = User::factory()->withWorkspace()->create();

    $workspace = $owner->currentWorkspace;
    $workspace->update([
        'onboarding_use_case' => OnboardingUseCase::Sales,
        'onboarding_referral_source' => OnboardingReferralSource::Google,
    ]);

    (new WorkspaceCreatedTagListener)->handle(new WorkspaceCreated($workspace->fresh()));

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $owner->id);
});

test('dispatches when only the use case is set', function (): void {
    $owner = User::factory()->withWorkspace()->create();

    $workspace = $owner->currentWorkspace;
    $workspace->update([
        'onboarding_use_case' => OnboardingUseCase::Marketing,
        'onboarding_referral_source' => null,
    ]);

    (new WorkspaceCreatedTagListener)->handle(new WorkspaceCreated($workspace->fresh()));

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $owner->id);
});

test('dispatches even when the owner has no mailcoach uuid yet', function (): void {
    $owner = User::factory()->withWorkspace()->create([
        'mailcoach_subscriber_uuid' => null,
    ]);

    $workspace = $owner->currentWorkspace;
    $workspace->update([
        'onboarding_use_case' => OnboardingUseCase::Sales,
    ]);

    (new WorkspaceCreatedTagListener)->handle(new WorkspaceCreated($workspace->fresh()));

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $owner->id);
});

test('skips dispatch when the workspace has no onboarding answers', function (): void {
    $owner = User::factory()->withWorkspace()->create();

    $workspace = $owner->currentWorkspace;
    $workspace->update([
        'onboarding_use_case' => null,
        'onboarding_referral_source' => null,
    ]);

    (new WorkspaceCreatedTagListener)->handle(new WorkspaceCreated($workspace->fresh()));

    Queue::assertNotPushed(SyncSubscriberJob::class);
});

test('dispatches for a second workspace with onboarding answers', function (): void {
    $owner = User::factory()->withWorkspace()->create();

    $secondWorkspace = $owner->ownedWorkspaces()->create([
        'name' => 'Second Workspace',
        'slug' => 'second-workspace',
        'personal_workspace' => false,
        'onboarding_use_case' => OnboardingUseCase::Recruiting,
        'onboarding_referral_source' => OnboardingReferralSource::LinkedIn,
    ]);

    Queue::fake([SyncSubscriberJob::class]);

    (new WorkspaceCreatedTagListener)->handle(new WorkspaceCreated($secondWorkspace));

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $owner->id);
});
