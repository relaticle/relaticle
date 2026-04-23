<?php

declare(strict_types=1);

use App\Actions\Jetstream\CancelUserDeletion;
use App\Actions\Jetstream\ScheduleUserDeletion;
use App\Models\Team;
use App\Models\User;
use App\Notifications\UserDeletionCancelledNotification;
use App\Notifications\UserDeletionScheduledNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

mutates(ScheduleUserDeletion::class, CancelUserDeletion::class);

test('user can schedule account deletion', function () {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create();

    resolve(ScheduleUserDeletion::class)->schedule($user);

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull()
        ->and($user->scheduled_deletion_at->isSameDay(now()->addDays(config('relaticle.deletion.grace_period_days'))))->toBeTrue();

    Notification::assertSentTo($user, UserDeletionScheduledNotification::class);
});

test('personal team is scheduled for deletion alongside user', function () {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $personalTeam = $user->personalTeam();

    resolve(ScheduleUserDeletion::class)->schedule($user);

    expect($personalTeam->refresh()->scheduled_deletion_at)->not->toBeNull();
});

test('user retains team memberships during grace period', function () {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->users()->attach($user, ['role' => 'editor']);

    expect($user->teams)->toHaveCount(1);

    resolve(ScheduleUserDeletion::class)->schedule($user);

    expect($user->refresh()->teams)->toHaveCount(1);
});

test('user cannot schedule deletion when owning team with other members', function () {
    Notification::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;
    $team->users()->attach(User::factory()->create(), ['role' => 'editor']);

    expect(fn () => resolve(ScheduleUserDeletion::class)->schedule($user))
        ->toThrow(ValidationException::class);

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('user can cancel scheduled deletion', function () {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->scheduledForDeletion()->create();
    $personalTeam = $user->personalTeam();
    $personalTeam->update(['scheduled_deletion_at' => $user->scheduled_deletion_at]);

    resolve(CancelUserDeletion::class)->cancel($user);

    expect($user->refresh()->scheduled_deletion_at)->toBeNull()
        ->and($personalTeam->refresh()->scheduled_deletion_at)->toBeNull();

    Notification::assertSentTo($user, UserDeletionCancelledNotification::class);
});
