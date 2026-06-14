<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

uses(LazilyRefreshDatabase::class);

function seedResolvedConv(string $id, User $user): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seedResolvedAssistantMsg(string $conversationId, User $user, DateTimeInterface $at): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'user_id' => $user->getKey(),
        'agent' => 'crm',
        'role' => 'assistant',
        'content' => 'ok',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

it('surfaces only actions resolved after the last assistant message', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    seedResolvedConv('conv-1', $user);

    seedResolvedAssistantMsg('conv-1', $user, now()->subMinutes(5));

    PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-1', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
        'action_data' => ['title' => 'Stale'], 'display_data' => [],
        'status' => PendingActionStatus::Approved, 'expires_at' => now(),
        'resolved_at' => now()->subMinutes(10), 'result_data' => ['id' => 'old-id'],
    ]);

    PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-1', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
        'action_data' => ['title' => 'Fresh Task'], 'display_data' => [],
        'status' => PendingActionStatus::Approved, 'expires_at' => now(),
        'resolved_at' => now()->subMinute(), 'result_data' => ['id' => 'new-id'],
    ]);

    $resolved = resolve(PendingActionService::class)->resolvedSinceLastAssistantMessage('conv-1');

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['label'])->toBe('Fresh Task')
        ->and($resolved[0]['status'])->toBe('approved')
        ->and($resolved[0]['record_id'])->toBe('new-id')
        ->and($resolved[0]['operation'])->toBe('create')
        ->and($resolved[0]['entity_type'])->toBe('task');
});

it('returns an empty list for another conversation', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    seedResolvedConv('conv-1', $user);

    PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-1', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
        'action_data' => ['title' => 'X'], 'display_data' => [],
        'status' => PendingActionStatus::Approved, 'expires_at' => now(),
        'resolved_at' => now(), 'result_data' => ['id' => 'x'],
    ]);

    expect(resolve(PendingActionService::class)->resolvedSinceLastAssistantMessage('other-conv'))->toBe([]);
});

it('surfaces an approval even when the continuation never journals it (Bug A)', function (): void {
    Bus::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    seedResolvedConv('conv-A', $user);
    seedResolvedAssistantMsg('conv-A', $user, now()->subMinutes(2));

    $pending = PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-A', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
        'action_data' => ['title' => 'Review Q3 sales pipeline'], 'display_data' => [],
        'status' => PendingActionStatus::Pending, 'expires_at' => now()->addMinutes(15),
    ]);

    resolve(PendingActionService::class)->approve($pending, $user);

    $resolved = resolve(PendingActionService::class)->resolvedSinceLastAssistantMessage('conv-A');

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['status'])->toBe('approved')
        ->and($resolved[0]['label'])->toBe('Review Q3 sales pipeline');
});
