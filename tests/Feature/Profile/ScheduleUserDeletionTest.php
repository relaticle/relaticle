<?php

declare(strict_types=1);

use App\Actions\Jetstream\CancelUserDeletion;
use App\Actions\Jetstream\ScheduleUserDeletion;
use App\Features\AccountDeletion;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\UserDeletionCancelledNotification;
use App\Notifications\UserDeletionScheduledNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

mutates(ScheduleUserDeletion::class, CancelUserDeletion::class);

beforeEach(function (): void {
    Feature::define(AccountDeletion::class, true);
});

test('user can schedule account deletion', function () {
    Notification::fake();

    $user = User::factory()->withPersonalWorkspace()->create();

    resolve(ScheduleUserDeletion::class)->schedule($user);

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull()
        ->and($user->scheduled_deletion_at->isSameDay(now()->addDays(config('relaticle.deletion.grace_period_days'))))->toBeTrue();

    Notification::assertSentTo($user, UserDeletionScheduledNotification::class);
});

test('personal workspace is scheduled for deletion alongside user', function () {
    Notification::fake();

    $user = User::factory()->withPersonalWorkspace()->create();
    $personalWorkspace = $user->personalWorkspace();

    resolve(ScheduleUserDeletion::class)->schedule($user);

    expect($personalWorkspace->refresh()->scheduled_deletion_at)->not->toBeNull();
});

test('user retains workspace memberships during grace period', function () {
    Notification::fake();

    $user = User::factory()->withPersonalWorkspace()->create();
    $otherWorkspace = Workspace::factory()->create();
    $otherWorkspace->users()->attach($user, ['role' => 'editor']);

    expect($user->workspaces)->toHaveCount(1);

    resolve(ScheduleUserDeletion::class)->schedule($user);

    expect($user->refresh()->workspaces)->toHaveCount(1);
});

test('user cannot schedule deletion when owning workspace with other members', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->users()->attach(User::factory()->create(), ['role' => 'editor']);

    expect(fn () => resolve(ScheduleUserDeletion::class)->schedule($user))
        ->toThrow(ValidationException::class);

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('the deletion blocker names an action the app supports', function (): void {
    Notification::fake();

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->users()->attach(User::factory()->create(), ['role' => 'editor']);

    try {
        resolve(ScheduleUserDeletion::class)->schedule($owner);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        $message = $exception->validator->errors()->first('workspace');

        expect($message)->not->toContain('Transfer ownership')
            ->and($message)->toContain($workspace->name);
    }
});

test('user can cancel scheduled deletion', function () {
    Notification::fake();
    Feature::define(AccountDeletion::class, false);

    $user = User::factory()->withPersonalWorkspace()->scheduledForDeletion()->create();
    $personalWorkspace = $user->personalWorkspace();
    $personalWorkspace->update(['scheduled_deletion_at' => $user->scheduled_deletion_at]);

    resolve(CancelUserDeletion::class)->cancel($user);

    expect($user->refresh()->scheduled_deletion_at)->toBeNull()
        ->and($personalWorkspace->refresh()->scheduled_deletion_at)->toBeNull();

    Notification::assertSentTo($user, UserDeletionCancelledNotification::class);
});
