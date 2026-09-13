<?php

declare(strict_types=1);

use App\Actions\Jetstream\CancelWorkspaceDeletion;
use App\Actions\Jetstream\ScheduleWorkspaceDeletion;
use App\Models\User;
use App\Notifications\WorkspaceDeletionCancelledNotification;
use App\Notifications\WorkspaceDeletionScheduledNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

mutates(ScheduleWorkspaceDeletion::class, CancelWorkspaceDeletion::class);

test('workspace owner can schedule workspace deletion', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    resolve(ScheduleWorkspaceDeletion::class)->schedule($user, $workspace);

    expect($workspace->refresh()->scheduled_deletion_at)->not->toBeNull()
        ->and($workspace->scheduled_deletion_at->isSameDay(now()->addDays(config('relaticle.deletion.grace_period_days'))))->toBeTrue();
});

test('workspace owner is notified when workspace is scheduled for deletion', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'editor']);

    resolve(ScheduleWorkspaceDeletion::class)->schedule($user, $workspace);

    Notification::assertSentTo($user, WorkspaceDeletionScheduledNotification::class);
    Notification::assertNotSentTo($member, WorkspaceDeletionScheduledNotification::class);
});

test('pending invitations are cancelled when workspace deletion is scheduled', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->workspaceInvitations()->create([
        'email' => 'invited@example.com',
        'role' => 'editor',
        'expires_at' => now()->addDays(7),
    ]);

    expect($workspace->workspaceInvitations)->toHaveCount(1);

    resolve(ScheduleWorkspaceDeletion::class)->schedule($user, $workspace);

    expect($workspace->refresh()->workspaceInvitations)->toBeEmpty();
});

test('personal workspace cannot be directly scheduled for deletion', function () {
    Notification::fake();

    $user = User::factory()->withPersonalWorkspace()->create();
    $personalWorkspace = $user->personalWorkspace();

    expect(fn () => resolve(ScheduleWorkspaceDeletion::class)->schedule($user, $personalWorkspace))
        ->toThrow(ValidationException::class);
});

test('non-owner cannot schedule workspace deletion', function () {
    Notification::fake();

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'editor']);

    expect(fn () => resolve(ScheduleWorkspaceDeletion::class)->schedule($member, $workspace))
        ->toThrow(AuthorizationException::class);
});

test('workspace owner can cancel workspace deletion', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(30)])->save();
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'editor']);

    resolve(CancelWorkspaceDeletion::class)->cancel($user, $workspace);

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();

    Notification::assertSentTo($user, WorkspaceDeletionCancelledNotification::class);
    Notification::assertNotSentTo($member, WorkspaceDeletionCancelledNotification::class);
});
