<?php

declare(strict_types=1);

use App\Actions\Jetstream\CancelTeamDeletion;
use App\Actions\Jetstream\ScheduleTeamDeletion;
use App\Models\User;
use App\Notifications\TeamDeletionCancelledNotification;
use App\Notifications\TeamDeletionScheduledNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

mutates(ScheduleTeamDeletion::class, CancelTeamDeletion::class);

test('team owner can schedule team deletion', function () {
    Notification::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    resolve(ScheduleTeamDeletion::class)->schedule($user, $team);

    expect($team->refresh()->scheduled_deletion_at)->not->toBeNull()
        ->and($team->scheduled_deletion_at->isSameDay(now()->addDays(config('relaticle.deletion.grace_period_days'))))->toBeTrue();
});

test('team owner is notified when team is scheduled for deletion', function () {
    Notification::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;
    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);

    resolve(ScheduleTeamDeletion::class)->schedule($user, $team);

    Notification::assertSentTo($user, TeamDeletionScheduledNotification::class);
    Notification::assertNotSentTo($member, TeamDeletionScheduledNotification::class);
});

test('pending invitations are cancelled when team deletion is scheduled', function () {
    Notification::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;
    $team->teamInvitations()->create([
        'email' => 'invited@example.com',
        'role' => 'editor',
        'expires_at' => now()->addDays(7),
    ]);

    expect($team->teamInvitations)->toHaveCount(1);

    resolve(ScheduleTeamDeletion::class)->schedule($user, $team);

    expect($team->refresh()->teamInvitations)->toHaveCount(0);
});

test('personal team cannot be directly scheduled for deletion', function () {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $personalTeam = $user->personalTeam();

    expect(fn () => resolve(ScheduleTeamDeletion::class)->schedule($user, $personalTeam))
        ->toThrow(ValidationException::class);
});

test('non-owner cannot schedule team deletion', function () {
    Notification::fake();

    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;
    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);

    expect(fn () => resolve(ScheduleTeamDeletion::class)->schedule($member, $team))
        ->toThrow(AuthorizationException::class);
});

test('team owner can cancel team deletion', function () {
    Notification::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['scheduled_deletion_at' => now()->addDays(30)])->save();
    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);

    resolve(CancelTeamDeletion::class)->cancel($user, $team);

    expect($team->refresh()->scheduled_deletion_at)->toBeNull();

    Notification::assertSentTo($user, TeamDeletionCancelledNotification::class);
    Notification::assertNotSentTo($member, TeamDeletionCancelledNotification::class);
});
