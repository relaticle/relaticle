<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;

function seedSupersedeConversation(User $user): array
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'supersede test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ids = [];

    foreach ([
        ['user', 'first question'],
        ['assistant', 'first answer'],
        ['user', 'second question'],
        ['assistant', 'second answer'],
    ] as [$role, $content]) {
        $id = (string) Str::uuid7();
        $ids[] = $id;

        DB::table('agent_conversation_messages')->insert([
            'id' => $id,
            'conversation_id' => $conversationId,
            'user_id' => (string) $user->getKey(),
            'agent' => 'test',
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return [$conversationId, $ids];
}

it('supersedes the anchor user message and everything after it', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [$conversationId, $ids] = seedSupersedeConversation($user);

    $response = $this->actingAs($user)->postJson("/chat/conversations/{$conversationId}/messages/supersede", [
        'anchor_id' => $ids[2],
    ]);

    $response->assertOk();
    expect($response->json('superseded'))->toBe(2);

    $remaining = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->whereNull('superseded_at')
        ->orderBy('id')
        ->pluck('content')
        ->all();

    expect($remaining)->toBe(['first question', 'first answer']);
});

it('anchors on the latest user row when no anchor_id is given and content matches', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [$conversationId] = seedSupersedeConversation($user);

    $response = $this->actingAs($user)->postJson("/chat/conversations/{$conversationId}/messages/supersede", [
        'anchor_content' => 'second question',
    ]);

    $response->assertOk();
    expect($response->json('superseded'))->toBe(2);
});

it('refuses to supersede when anchor_content does not match the latest user row', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [$conversationId] = seedSupersedeConversation($user);

    $response = $this->actingAs($user)->postJson("/chat/conversations/{$conversationId}/messages/supersede", [
        'anchor_content' => 'a message that was never persisted',
    ]);

    $response->assertOk();
    expect($response->json('superseded'))->toBe(0);

    expect(DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->whereNull('superseded_at')
        ->count())->toBe(4);
});

it('rejects an assistant message as anchor', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [$conversationId, $ids] = seedSupersedeConversation($user);

    $this->actingAs($user)
        ->postJson("/chat/conversations/{$conversationId}/messages/supersede", ['anchor_id' => $ids[3]])
        ->assertStatus(422);
});

it('hides foreign conversations with 404', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    [$conversationId] = seedSupersedeConversation($owner);

    $intruder = User::factory()->withPersonalTeam()->create();

    $this->actingAs($intruder)
        ->postJson("/chat/conversations/{$conversationId}/messages/supersede", [])
        ->assertNotFound();
});

it('excludes superseded messages from the rendered transcript', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [$conversationId, $ids] = seedSupersedeConversation($user);

    $this->actingAs($user)
        ->postJson("/chat/conversations/{$conversationId}/messages/supersede", ['anchor_id' => $ids[2]])
        ->assertOk();

    $messages = resolve(ListConversationMessages::class)->execute($user, $conversationId);

    $contents = array_map(
        static fn (array $message): string => trim(strip_tags($message['content'])),
        $messages,
    );

    expect($contents)->toBe(['first question', 'first answer']);
});

it('excludes superseded messages from the agent history', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    [$conversationId, $ids] = seedSupersedeConversation($user);

    $this->actingAs($user)
        ->postJson("/chat/conversations/{$conversationId}/messages/supersede", ['anchor_id' => $ids[2]])
        ->assertOk();

    $store = resolve(ConversationStore::class);

    expect($store)->toBeInstanceOf(SupersededAwareConversationStore::class);

    $history = $store->getLatestConversationMessages($conversationId, 100);

    expect($history)->toHaveCount(2)
        ->and($history->first()->content)->toBe('first question')
        ->and($history->last()->content)->toBe('first answer');
});
