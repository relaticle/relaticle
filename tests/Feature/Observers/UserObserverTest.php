<?php

declare(strict_types=1);

use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

mutates(UserObserver::class);

function seedChatParticipation(User $participant, string $teamId): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $participant->getMorphClass(),
        'participant_id' => (string) $participant->id,
        'team_id' => $teamId,
        'title' => 'Member conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $participant->getMorphClass(),
        'participant_id' => (string) $participant->id,
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

    return $conversationId;
}

it('anonymises chat participation on a plain eloquent delete', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);

    $conversationId = seedChatParticipation($member, (string) $team->id);

    $member->delete();

    $conversation = DB::table('agent_conversations')->where('id', $conversationId)->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation->participant_type)->toBeNull()
        ->and($conversation->participant_id)->toBeNull()
        ->and(DB::table('agent_conversation_messages')->where('participant_id', (string) $member->id)->exists())->toBeFalse();
});

it('leaves other participants untouched when a user is deleted', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);

    $survivorConversationId = seedChatParticipation($owner, (string) $team->id);
    seedChatParticipation($member, (string) $team->id);

    $member->delete();

    $survivor = DB::table('agent_conversations')->where('id', $survivorConversationId)->first();

    expect($survivor->participant_type)->toBe($owner->getMorphClass())
        ->and($survivor->participant_id)->toBe((string) $owner->id);
});
