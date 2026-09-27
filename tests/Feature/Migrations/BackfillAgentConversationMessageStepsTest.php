<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;

function restoreLegacyToolColumns(): void
{
    Schema::table('agent_conversation_messages', function (Blueprint $table): void {
        $table->jsonb('tool_calls')->default(DB::raw("'[]'::jsonb"));
        $table->jsonb('tool_results')->default(DB::raw("'[]'::jsonb"));
        $table->text('approval_state')->nullable();
    });

    DB::statement("ALTER TABLE agent_conversation_messages ALTER COLUMN steps SET DEFAULT '[]'::jsonb");
}

function runBackfillAgentConversationMessageStepsMigration(): void
{
    $path = glob(database_path('migrations/*_backfill_agent_conversation_message_steps.php'))[0];

    (require $path)->up();
}

/**
 * @param  list<array<string, mixed>>  $toolCalls
 * @param  list<array<string, mixed>>  $toolResults
 * @param  array<string, mixed>  $meta
 */
function legacyTurnRow(string $conversationId, User $user, string $role, string $content, int $offset, array $toolCalls = [], array $toolResults = [], array $meta = []): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversation_messages')->insert([
        'id' => $id,
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'crm',
        'role' => $role,
        'content' => $content,
        'attachments' => '[]',
        'tool_calls' => json_encode($toolCalls, JSON_THROW_ON_ERROR),
        'tool_results' => json_encode($toolResults, JSON_THROW_ON_ERROR),
        'usage' => '[]',
        'meta' => json_encode((object) $meta, JSON_THROW_ON_ERROR),
        'created_at' => now()->addSeconds($offset),
        'updated_at' => now()->addSeconds($offset),
    ]);

    return $id;
}

/** @return list<array<string, mixed>> */
function backfilledSteps(string $messageId): array
{
    return json_decode((string) DB::table('agent_conversation_messages')->where('id', $messageId)->value('steps'), true);
}

/**
 * @param  list<array<string, mixed>>  $toolCalls
 * @return array<string, mixed>
 */
function expectedStep(string $content, array $toolCalls = [], string $reasoning = ''): array
{
    return [
        'content' => $content,
        'tool_calls' => $toolCalls,
        'reasoning' => $reasoning,
        'replay_blocks' => [],
        'provider_tool_calls' => [],
    ];
}

beforeEach(function (): void {
    restoreLegacyToolColumns();

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $this->conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->user->currentWorkspace->getKey(),
        'title' => 'legacy',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('splits a turn that called a tool and then replied into a call step and a reply step', function (): void {
    $call = ['id' => 'toolu_1', 'name' => 'ListCompaniesTool', 'arguments' => ['search' => 'Acme'], 'result_id' => 'toolu_1', 'reasoning_id' => 'rs_1'];
    $result = ['id' => 'toolu_1', 'name' => 'ListCompaniesTool', 'arguments' => ['search' => 'Acme'], 'result' => '{"data":[]}', 'result_id' => 'toolu_1'];

    legacyTurnRow($this->conversationId, $this->user, 'user', 'find Acme', 0);
    $assistant = legacyTurnRow($this->conversationId, $this->user, 'assistant', 'Here is Acme.', 1, [$call], [$result], ['reasoning' => 'look it up', 'provider' => 'anthropic']);

    runBackfillAgentConversationMessageStepsMigration();

    expect(backfilledSteps($assistant))->toEqual([
        expectedStep('', [['id' => 'toolu_1', 'name' => 'ListCompaniesTool', 'arguments' => ['search' => 'Acme'], 'result_id' => 'toolu_1', 'result' => '{"data":[]}']]),
        expectedStep('Here is Acme.', [], 'look it up'),
    ])
        ->and(json_decode((string) DB::table('agent_conversation_messages')->where('id', $assistant)->value('meta'), true))
        ->toEqual(['provider' => 'anthropic']);
});

it('stores a text-only reply as one step and leaves user rows empty', function (): void {
    $user = legacyTurnRow($this->conversationId, $this->user, 'user', 'hello', 0);
    $assistant = legacyTurnRow($this->conversationId, $this->user, 'assistant', 'Plain answer.', 1);

    runBackfillAgentConversationMessageStepsMigration();

    expect(backfilledSteps($user))->toBe([])
        ->and(backfilledSteps($assistant))->toEqual([expectedStep('Plain answer.')]);
});

it('lands a result recorded on a later row on the call that made it', function (): void {
    $call = ['id' => 'toolu_2', 'name' => 'CreateTaskTool', 'arguments' => [], 'result_id' => 'toolu_2'];
    $result = ['id' => 'toolu_2', 'name' => 'CreateTaskTool', 'arguments' => [], 'result' => '{"type":"pending_action"}', 'result_id' => 'toolu_2'];

    legacyTurnRow($this->conversationId, $this->user, 'user', 'add a task', 0);
    $calling = legacyTurnRow($this->conversationId, $this->user, 'assistant', '', 1, [$call]);
    $answering = legacyTurnRow($this->conversationId, $this->user, 'assistant', 'Done.', 2, [], [$result]);

    runBackfillAgentConversationMessageStepsMigration();

    expect(backfilledSteps($calling))->toEqual([expectedStep('', [[...$call, 'result' => '{"type":"pending_action"}']])])
        ->and(backfilledSteps($answering))->toEqual([expectedStep('Done.')]);
});

it('drops the flat tool columns and the steps default once every row is backfilled', function (): void {
    legacyTurnRow($this->conversationId, $this->user, 'assistant', 'Plain answer.', 0);

    runBackfillAgentConversationMessageStepsMigration();

    $stepsDefault = DB::scalar("SELECT column_default FROM information_schema.columns WHERE table_name = 'agent_conversation_messages' AND column_name = 'steps'");

    expect(Schema::hasColumns('agent_conversation_messages', ['tool_calls', 'tool_results', 'approval_state']))->toBeFalse()
        ->and($stepsDefault)->toBeNull();
});

it('replays a backfilled tool turn to the model as call, result, then reply', function (): void {
    $call = ['id' => 'toolu_3', 'name' => 'ListCompaniesTool', 'arguments' => [], 'result_id' => 'toolu_3'];
    $result = ['id' => 'toolu_3', 'name' => 'ListCompaniesTool', 'arguments' => [], 'result' => '{"data":["Acme"]}', 'result_id' => 'toolu_3'];

    legacyTurnRow($this->conversationId, $this->user, 'user', 'list companies', 0);
    legacyTurnRow($this->conversationId, $this->user, 'assistant', 'You have Acme.', 1, [$call], [$result]);

    runBackfillAgentConversationMessageStepsMigration();

    $history = resolve(ConversationStore::class)->getLatestConversationMessages($this->conversationId, 100)->values();

    expect($history)->toHaveCount(4)
        ->and($history[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($history[1]->toolCalls->first()->id)->toBe('toolu_3')
        ->and($history[2])->toBeInstanceOf(ToolResultMessage::class)
        ->and($history[2]->toolResults->first()->result)->toBe('{"data":["Acme"]}')
        ->and($history[3]->content)->toBe('You have Acme.');
});
