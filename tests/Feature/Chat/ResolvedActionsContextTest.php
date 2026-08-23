<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

uses(LazilyRefreshDatabase::class);

function seedResolvedConv(string $id, User $user): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
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
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
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

it('keeps actions resolved before the last assistant message in context', function (): void {
    // Regression: a rejection followed by any later assistant turn used to fall
    // out of the resolved window, leaving only the stale "pending approval"
    // tool result in the replayed transcript — the model then told the user the
    // proposal was still awaiting approval instead of proposing again.
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    seedResolvedConv('conv-1', $user);

    seedResolvedAssistantMsg('conv-1', $user, now()->subMinutes(5));

    PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-1', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Delete, 'entity_type' => 'task',
        'action_data' => ['title' => 'Rejected before last turn'], 'display_data' => [],
        'status' => PendingActionStatus::Rejected, 'expires_at' => now(),
        'resolved_at' => now()->subMinutes(10),
    ]);

    PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-1', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
        'action_data' => ['title' => 'Fresh Task'], 'display_data' => [],
        'status' => PendingActionStatus::Approved, 'expires_at' => now(),
        'resolved_at' => now()->subMinute(), 'result_data' => ['id' => 'new-id'],
    ]);

    $resolved = resolve(PendingActionService::class)->resolvedForConversation('conv-1');

    expect($resolved)->toHaveCount(2)
        ->and($resolved[0]['label'])->toBe('Rejected before last turn')
        ->and($resolved[0]['status'])->toBe('rejected')
        ->and($resolved[1]['label'])->toBe('Fresh Task')
        ->and($resolved[1]['status'])->toBe('approved')
        ->and($resolved[1]['record_id'])->toBe('new-id');
});

it('caps the context at the 20 newest resolutions, oldest first', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    seedResolvedConv('conv-1', $user);

    foreach (range(1, 21) as $i) {
        PendingAction::query()->create([
            'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
            'conversation_id' => 'conv-1', 'action_class' => CreateTask::class,
            'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
            'action_data' => ['title' => "Task {$i}"], 'display_data' => [],
            'status' => PendingActionStatus::Approved, 'expires_at' => now(),
            'resolved_at' => now()->subMinutes(30 - $i), 'result_data' => ['id' => "id-{$i}"],
        ]);
    }

    $resolved = resolve(PendingActionService::class)->resolvedForConversation('conv-1');

    expect($resolved)->toHaveCount(20)
        ->and($resolved[0]['label'])->toBe('Task 2')
        ->and($resolved[19]['label'])->toBe('Task 21');
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

    expect(resolve(PendingActionService::class)->resolvedForConversation('other-conv'))->toBe([]);
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

    $resolved = resolve(PendingActionService::class)->resolvedForConversation('conv-A');

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['status'])->toBe('approved')
        ->and($resolved[0]['label'])->toBe('Review Q3 sales pipeline');
});

it('labels a task create and update by the record title, never by the card heading', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    seedResolvedConv('conv-L', $user);

    PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-L', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
        'action_data' => ['title' => 'Review Q3 sales pipeline'],
        'display_data' => ['title' => 'Create Task', 'summary' => 'Create task "Review Q3 sales pipeline"', 'fields' => [['label' => 'Title', 'value' => 'Review Q3 sales pipeline']]],
        'status' => PendingActionStatus::Approved, 'expires_at' => now(),
        'resolved_at' => now()->subMinutes(2), 'result_data' => ['id' => 'task-1'],
    ]);

    PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-L', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Update, 'entity_type' => 'note',
        'action_data' => ['title' => 'Test Note 1 🚀', '_record_id' => 'note-1'],
        'display_data' => ['title' => 'Update Note', 'summary' => 'Update note "Test Note 1"', 'fields' => [['label' => 'Title', 'old' => 'Test Note 1', 'new' => 'Test Note 1 🚀']]],
        'status' => PendingActionStatus::Approved, 'expires_at' => now(),
        'resolved_at' => now()->subMinute(), 'result_data' => ['id' => 'note-1'],
    ]);

    $resolved = resolve(PendingActionService::class)->resolvedForConversation('conv-L');

    expect($resolved[0]['label'])->toBe('Review Q3 sales pipeline')
        ->and($resolved[0]['records'])->toBe([['id' => 'task-1', 'label' => 'Review Q3 sales pipeline', 'url' => '/r/task/task-1']])
        ->and($resolved[1]['label'])->toBe('Test Note 1 🚀')
        ->and($resolved[1]['records'])->toBe([['id' => 'note-1', 'label' => 'Test Note 1 🚀', 'url' => '/r/note/note-1']]);
});

it('labels a delete by the record name from the card fields', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    seedResolvedConv('conv-D', $user);

    PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-D', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Delete, 'entity_type' => 'company',
        'action_data' => ['_record_ids' => ['co-1'], '_model_class' => 'App\\Models\\Company'],
        'display_data' => ['title' => 'Delete Company', 'summary' => 'Delete Company "Acme"', 'fields' => [['label' => 'Name', 'value' => 'Acme']]],
        'status' => PendingActionStatus::Approved, 'expires_at' => now(),
        'resolved_at' => now(), 'result_data' => [],
    ]);

    $resolved = resolve(PendingActionService::class)->resolvedForConversation('conv-D');

    expect($resolved[0]['label'])->toBe('Acme')
        ->and($resolved[0]['records'])->toBe([]);
});

it('labels each record of an approved batch with its own title and url', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    seedResolvedConv('conv-B', $user);

    PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-B', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'note',
        'action_data' => ['_batch' => true, 'records' => [['title' => 'Alpha'], ['title' => 'Beta'], ['title' => 'Gamma']]],
        'display_data' => ['title' => 'Create Notes', 'summary' => 'Create 3 notes', 'items' => [
            ['title' => 'Create Note', 'fields' => [['label' => 'Title', 'value' => 'Alpha']]],
            ['title' => 'Create Note', 'fields' => [['label' => 'Title', 'value' => 'Beta']]],
            ['title' => 'Create Note', 'fields' => [['label' => 'Title', 'value' => 'Gamma']]],
        ]],
        'status' => PendingActionStatus::Approved, 'expires_at' => now(),
        'resolved_at' => now(), 'result_data' => [
            'ids' => ['n-a', 'n-c'], 'count' => 2, 'type' => 'note',
            'items' => ['0' => ['status' => 'approved', 'id' => 'n-a'], '1' => ['status' => 'rejected'], '2' => ['status' => 'approved', 'id' => 'n-c']],
        ],
    ]);

    $resolved = resolve(PendingActionService::class)->resolvedForConversation('conv-B');

    expect($resolved[0]['label'])->toBe('Alpha, Beta, Gamma')
        ->and($resolved[0]['records'])->toBe([
            ['id' => 'n-a', 'label' => 'Alpha', 'url' => '/r/note/n-a'],
            ['id' => 'n-c', 'label' => 'Gamma', 'url' => '/r/note/n-c'],
        ]);
});

it('leaves superseded proposals to their own block instead of listing them as decided', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    seedResolvedConv('conv-S', $user);

    PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-S', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
        'action_data' => ['title' => 'Prepare Q4 deck'], 'display_data' => [],
        'status' => PendingActionStatus::Superseded, 'expires_at' => now(),
        'resolved_at' => now(),
    ]);

    expect(resolve(PendingActionService::class)->resolvedForConversation('conv-S'))->toBe([]);
});

it('stamps the decided status onto replayed proposal tool results so the transcript stops claiming they are pending', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    seedResolvedConv('conv-R', $user);

    $approved = PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-R', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
        'action_data' => ['title' => 'Ship it'], 'display_data' => ['title' => 'Create Task', 'fields' => [['label' => 'Title', 'value' => 'Ship it']]],
        'status' => PendingActionStatus::Approved, 'expires_at' => now(),
        'resolved_at' => now(), 'result_data' => ['id' => 'task-1'],
    ]);
    $pending = PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-R', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
        'action_data' => ['title' => 'Still open'], 'display_data' => [],
        'status' => PendingActionStatus::Pending, 'expires_at' => now()->addHour(),
    ]);

    $toolResult = static fn (PendingAction $action, string $callId): array => [
        'id' => $callId, 'name' => 'CreateTaskTool', 'arguments' => [],
        'result' => json_encode([
            'type' => 'pending_action', 'pending_action_id' => $action->getKey(), 'operation' => 'create',
            'data' => $action->action_data, 'display' => $action->display_data, 'meta' => ['agent_should_stop' => true],
        ]),
    ];

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(), 'conversation_id' => 'conv-R', 'participant_type' => 'user',
        'participant_id' => $user->getKey(), 'agent' => 'crm', 'role' => 'assistant', 'content' => 'Review the proposals below.',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'CreateTaskTool', 'arguments' => [], 'result_id' => 'call-1'],
            ['id' => 'call-2', 'name' => 'CreateTaskTool', 'arguments' => [], 'result_id' => 'call-2'],
        ]),
        'tool_results' => json_encode([$toolResult($approved, 'call-1'), $toolResult($pending, 'call-2')]),
        'usage' => '{}', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $history = (string) json_encode(resolve(ConversationStore::class)->getLatestConversationMessages('conv-R', 100));
    $decoded = json_decode($history, true);
    $results = collect($decoded)->flatMap(static fn (array $message): array => $message['toolResults'] ?? [])
        ->map(static fn (array $result): array => json_decode($result['result'], true))
        ->keyBy('pending_action_id');

    expect($results[$approved->getKey()]['type'])->toBe('resolved_action')
        ->and($results[$approved->getKey()]['status'])->toBe('approved')
        ->and($results[$approved->getKey()])->not->toHaveKey('display')
        ->and($results[$approved->getKey()]['data']['title'])->toBe('Ship it')
        ->and($results[$pending->getKey()]['type'])->toBe('pending_action');
});
