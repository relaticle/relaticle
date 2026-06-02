<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatDocument;

it('returns the persisted latest assistant message for reconciliation', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'assistant',
        'content' => 'Final answer',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId]);
    $result = $component->instance()->latestAssistantMessage();

    expect($result['content'])->toBe('Final answer');
});

it('returns the most recent assistant message when several exist', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $base = [
        'conversation_id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
    ];

    DB::table('agent_conversation_messages')->insert([
        ...$base,
        'id' => (string) Str::ulid(),
        'role' => 'assistant',
        'content' => 'Earlier answer',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);
    $latestId = (string) Str::ulid();
    DB::table('agent_conversation_messages')->insert([
        ...$base,
        'id' => $latestId,
        'role' => 'assistant',
        'content' => 'Latest answer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId]);
    $result = $component->instance()->latestAssistantMessage();

    expect($result)->toMatchArray(['id' => $latestId, 'content' => 'Latest answer']);
});

it('returns null when the conversation has no assistant message', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'user',
        'content' => 'A question',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId]);

    expect($component->instance()->latestAssistantMessage())->toBeNull();
});

it('returns null when there is no conversation', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $component = Livewire::test(ChatInterface::class);

    expect($component->instance()->latestAssistantMessage())->toBeNull();
});

it('does not leak another tenant assistant message (cross-tenant scoping)', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $attacker = User::factory()->withPersonalTeam()->create();

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $owner->getKey(),
        'team_id' => $owner->currentTeam->getKey(),
        'title' => 'Secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'user_id' => (string) $owner->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'assistant',
        'content' => 'Confidential answer',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($attacker);
    $component = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId]);

    expect($component->instance()->latestAssistantMessage())->toBeNull();
});
