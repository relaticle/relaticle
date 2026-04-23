<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use App\Notifications\TeamDeletionReminderNotification;
use App\Notifications\UserDeletionReminderNotification;
use Illuminate\Support\Facades\Notification;

test('expired users are permanently deleted', function () {
    $user = User::factory()->withPersonalTeam()->scheduledForDeletion(-1)->create();
    $userId = $user->id;

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    expect(User::query()->find($userId))->toBeNull();
});

test('non-expired users are not deleted', function () {
    $user = User::factory()->withPersonalTeam()->scheduledForDeletion(15)->create();

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    expect($user->refresh())->not->toBeNull();
});

test('expired teams are permanently deleted', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;
    $team->update(['scheduled_deletion_at' => now()->subDay()]);
    $teamId = $team->id;

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    expect(Team::query()->find($teamId))->toBeNull();
});

test('day 25 reminder is sent for users', function () {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->scheduledForDeletion(5)->create();

    $this->travelTo(now());

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    Notification::assertSentTo($user, UserDeletionReminderNotification::class);
});

test('day 25 reminder is sent to team owner only', function () {
    Notification::fake();

    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;
    $team->update(['scheduled_deletion_at' => now()->addDays(5)]);
    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    Notification::assertSentTo($owner, TeamDeletionReminderNotification::class);
    Notification::assertNotSentTo($member, TeamDeletionReminderNotification::class);
});
