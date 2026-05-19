<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('rename endpoint returns the conversation_id and the new title', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'Old title',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson("/chat/conversations/{$conversationId}/rename", [
        'title' => 'New title',
    ]);

    $response->assertOk();
    $response->assertJson([
        'title' => 'New title',
        'conversation_id' => $conversationId,
    ]);
});
