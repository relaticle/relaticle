<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Models\PendingAction;
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

it('returns still-pending proposal cards so a dropped tool_result can be reconciled (R7)', function (): void {
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
        'content' => 'Proposed it',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $pending = PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Reconcile me'],
        'display_data' => ['summary' => 'Create task', 'title' => 'Reconcile me'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $result = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->instance()->latestAssistantMessage();

    expect($result['pending_actions'])->toHaveCount(1)
        ->and($result['pending_actions'][0]['pending_action_id'])->toBe((string) $pending->getKey())
        ->and($result['pending_actions'][0]['status'])->toBe('pending')
        ->and($result['pending_actions'][0]['operation'])->toBe('create');
});

it('does not return resolved or expired cards for reconciliation', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(), 'title' => 'T',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(), 'conversation_id' => $conversationId,
        'user_id' => (string) $user->getKey(), 'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'assistant', 'content' => 'x', 'document' => ChatDocument::emptyJson(),
        'attachments' => '[]', 'tool_calls' => '[]', 'tool_results' => '[]', 'usage' => '{}', 'meta' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $base = [
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => $conversationId, 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
        'action_data' => ['title' => 'x'], 'display_data' => ['title' => 'x'],
    ];
    PendingAction::query()->create([...$base, 'status' => PendingActionStatus::Approved, 'expires_at' => now()->addMinutes(15), 'resolved_at' => now()]);
    PendingAction::query()->create([...$base, 'status' => PendingActionStatus::Pending, 'expires_at' => now()->subMinute()]);

    $result = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->instance()->latestAssistantMessage();

    expect($result['pending_actions'])->toBeEmpty();
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

it('exposes the conversation title for header sync', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'Create 3 random companies',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->call('conversationTitle')
        ->assertReturned('Create 3 random companies');
});

it('resolves the title from a client-supplied id when the server property is unset (first turn)', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'What companies do I have?',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ChatInterface::class)
        ->assertSet('conversationId', null)
        ->call('conversationTitle', $conversationId)
        ->assertReturned('What companies do I have?');
});

it('does not leak another tenant conversation title via a client-supplied id', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $attacker = User::factory()->withPersonalTeam()->create();

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $owner->getKey(),
        'team_id' => $owner->currentTeam->getKey(),
        'title' => 'Secret title',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($attacker);

    Livewire::test(ChatInterface::class)
        ->call('conversationTitle', $conversationId)
        ->assertReturned(null);
});
