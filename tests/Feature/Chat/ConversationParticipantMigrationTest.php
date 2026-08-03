<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('exposes polymorphic participant columns on both conversation tables', function (): void {
    expect(Schema::hasColumns('agent_conversations', ['participant_type', 'participant_id']))->toBeTrue()
        ->and(Schema::hasColumn('agent_conversations', 'user_id'))->toBeFalse()
        ->and(Schema::hasColumns('agent_conversation_messages', ['participant_type', 'participant_id', 'approval_state']))->toBeTrue()
        ->and(Schema::hasColumn('agent_conversation_messages', 'user_id'))->toBeFalse();
});

it('drops the user foreign keys so a polymorphic participant is storable', function (): void {
    $constraints = collect(DB::select(
        "SELECT conname FROM pg_constraint WHERE conrelid IN ('agent_conversations'::regclass, 'agent_conversation_messages'::regclass)"
    ))->pluck('conname');

    expect($constraints)->not->toContain('agent_conversations_user_id_foreign')
        ->and($constraints)->not->toContain('agent_conversation_messages_user_id_foreign')
        ->and($constraints)->toContain('agent_conversations_team_id_foreign');
});

it('stores a conversation participant using the enforced morph alias', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    DB::table('agent_conversations')->insert([
        'id' => (string) Str::uuid(),
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('agent_conversations')->value('participant_type'))->toBe('user');
});
