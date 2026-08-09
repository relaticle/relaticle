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

test('purging a user anonymises their chat participation in teams that survive', function () {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $member = User::factory()->scheduledForDeletion(-1)->create();
    $team->users()->attach($member, ['role' => 'editor']);

    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $member->getMorphClass(),
        'participant_id' => (string) $member->id,
        'team_id' => (string) $team->id,
        'title' => 'Member conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $member->getMorphClass(),
        'participant_id' => (string) $member->id,
        'agent' => 'test-agent',
        'role' => 'user',
        'content' => 'hello',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('app:purge-scheduled-deletions')
        ->assertExitCode(0);

    expect(User::query()->find($member->id))->toBeNull()
        ->and(DB::table('agent_conversations')->where('participant_id', (string) $member->id)->exists())->toBeFalse()
        ->and(DB::table('agent_conversation_messages')->where('participant_id', (string) $member->id)->exists())->toBeFalse();

    $conversation = DB::table('agent_conversations')->where('id', $conversationId)->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation->participant_id)->toBeNull()
        ->and($conversation->participant_type)->toBeNull();
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
