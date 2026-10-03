<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\CreditService;
use Tests\Helpers\AnthropicSse;
use Tests\Helpers\ChatDocument;

function latestAssistantSeedConversation(User $user, string $title = 'T'): string
{
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

/** @param array<string, mixed> $overrides */
function latestAssistantSeedMessage(User $user, string $conversationId, array $overrides = []): string
{
    $id = (string) Str::ulid();
    DB::table('agent_conversation_messages')->insert(array_merge([
        'id' => $id,
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'assistant',
        'content' => '',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

it('returns the persisted latest assistant message for reconciliation', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = latestAssistantSeedConversation($user);
    latestAssistantSeedMessage($user, $conversationId, ['content' => 'Final answer']);

    $component = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId]);
    $result = $component->instance()->latestAssistantMessage();

    expect($result['content'])->toBe('Final answer');
});

it('returns the acknowledgement that replaced a blank reply', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    $conversationId = latestAssistantSeedConversation($user);
    $turnId = (string) Str::ulid();

    resolve(CreditService::class)->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: $conversationId,
        userId: (string) $user->getKey(),
    );

    AnthropicSse::fake(AnthropicSse::reply('', 'claude-sonnet-5'));
    Queue::fake();

    (new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'Here is part one of my notes.',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: $turnId,
    ))->handle(resolve(CreditService::class));

    $this->actingAs($user);

    $result = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->instance()->latestAssistantMessage();

    expect($result['content'])->toBe(__('Noted. Go on, or ask me a question.'));
});

it('returns still-pending proposal cards so a dropped tool_result can be reconciled (R7)', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = latestAssistantSeedConversation($user);
    latestAssistantSeedMessage($user, $conversationId, ['content' => 'Proposed it']);
    $pending = PendingAction::query()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
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
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'participant_type' => 'user', 'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(), 'title' => 'T',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(), 'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(), 'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'assistant', 'content' => 'x', 'document' => ChatDocument::emptyJson(),
        'attachments' => '[]', 'steps' => '[]', 'usage' => '{}', 'meta' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $base = [
        'workspace_id' => $user->currentWorkspace->getKey(), 'user_id' => $user->getKey(),
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
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = latestAssistantSeedConversation($user);

    $base = [
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
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
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = latestAssistantSeedConversation($user);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'user',
        'content' => 'A question',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId]);

    expect($component->instance()->latestAssistantMessage())->toBeNull();
});

it('returns null when there is no conversation', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $component = Livewire::test(ChatInterface::class);

    expect($component->instance()->latestAssistantMessage())->toBeNull();
});

it('does not leak another tenant assistant message (cross-tenant scoping)', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $attacker = User::factory()->withPersonalWorkspace()->create();

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $owner->getKey(),
        'workspace_id' => $owner->currentWorkspace->getKey(),
        'title' => 'Secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $owner->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'assistant',
        'content' => 'Confidential answer',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($attacker);
    $component = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId]);

    expect($component->instance()->latestAssistantMessage())->toBeNull();
});

/**
 * The first turn of a new chat creates the conversation from the client's own
 * fetch, so the server component's $conversationId is still null when the
 * stream ends. Without the client-supplied id, reconcile got null back and the
 * turn's tables, which are never broadcast, so reconcile is their only path,
 * stayed missing until a full page reload.
 */
it('returns display blocks from a client-supplied id when the server property is unset (first turn)', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = latestAssistantSeedConversation($user);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'assistant',
        'content' => 'Here are your companies and contacts.',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => storedToolSteps([
            ['id' => 'toolu_1', 'name' => 'ListCompaniesTool', 'arguments' => [], 'result' => json_encode([
                'data' => [],
                'display_block' => ['block' => 'records_table', 'title' => 'Companies'],
            ])],
            ['id' => 'toolu_2', 'name' => 'ListPeopleTool', 'arguments' => [], 'result' => json_encode([
                'data' => [],
                'display_block' => ['block' => 'records_table', 'title' => 'People'],
            ])],
        ]),
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(ChatInterface::class)->assertSet('conversationId', null);
    $result = $component->instance()->latestAssistantMessage($conversationId);

    expect($result['content'])->toBe('Here are your companies and contacts.')
        ->and(array_column($result['display_blocks'], 'title'))->toBe(['Companies', 'People']);
});

it('does not leak another tenant assistant message via a client-supplied id', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $attacker = User::factory()->withPersonalWorkspace()->create();

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $owner->getKey(),
        'workspace_id' => $owner->currentWorkspace->getKey(),
        'title' => 'Secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $owner->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'assistant',
        'content' => 'Confidential answer',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($attacker);

    expect(Livewire::test(ChatInterface::class)->instance()->latestAssistantMessage($conversationId))->toBeNull();
});

it('exposes the conversation title for header sync', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = latestAssistantSeedConversation($user, 'Create 3 random companies');

    Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->call('conversationTitle')
        ->assertReturned('Create 3 random companies');
});

it('resolves the title from a client-supplied id when the server property is unset (first turn)', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $conversationId = latestAssistantSeedConversation($user, 'What companies do I have?');

    Livewire::test(ChatInterface::class)
        ->assertSet('conversationId', null)
        ->call('conversationTitle', $conversationId)
        ->assertReturned('What companies do I have?');
});

it('does not leak another tenant conversation title via a client-supplied id', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $attacker = User::factory()->withPersonalWorkspace()->create();

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $owner->getKey(),
        'workspace_id' => $owner->currentWorkspace->getKey(),
        'title' => 'Secret title',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($attacker);

    Livewire::test(ChatInterface::class)
        ->call('conversationTitle', $conversationId)
        ->assertReturned(null);
});
