<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AgentConversationMessage;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\RelaTurn;

function assistantRowWithResults(EvalWorkspace $workspace, array $toolCalls, array $usage = [], array $meta = []): AgentConversationMessage
{
    $conversation = $workspace->conversation();

    return AgentConversationMessage::query()->create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->getKey(),
        'participant_type' => 'user',
        'participant_id' => (string) $workspace->owner->getKey(),
        'agent' => CrmAssistant::class,
        'role' => 'assistant',
        'content' => 'Done.',
        'attachments' => '[]',
        'steps' => json_encode([[
            'content' => '',
            'tool_calls' => $toolCalls,
            'reasoning' => '',
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]], JSON_THROW_ON_ERROR),
        'usage' => json_encode($usage, JSON_THROW_ON_ERROR),
        'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('returns a json envelope with the reply text and tool calls', function (): void {
    CrmAssistant::fake(['Here are your companies.']);
    $turn = new RelaTurn(EvalWorkspace::seed());

    $envelope = json_decode($turn('show me my companies'), true);

    expect($envelope)->toHaveKeys(['text', 'tool_calls', 'tool_result_row_ids', 'replayed_bytes', 'usage', 'duration_ms', 'error'])
        ->and($envelope['text'])->toBe('Here are your companies.')
        ->and($envelope['error'])->toBeNull()
        ->and($envelope['usage'])->toHaveKeys(['input_tokens', 'output_tokens']);
});

it('counts replayed bytes without the display block', function (): void {
    $turn = new RelaTurn(EvalWorkspace::seed());
    $result = json_encode(['data' => [['id' => 'x']], 'display_block' => ['rows' => array_fill(0, 50, ['cells' => str_repeat('y', 100)])]]);

    expect($turn->replayedBytesOf([['result' => $result]]))->toBeLessThan(100);
});

it('uses a fresh conversation for every invocation', function (): void {
    CrmAssistant::fake(['one', 'two']);
    $workspace = EvalWorkspace::seed();
    $baseline = AgentConversation::query()->where('workspace_id', $workspace->workspace->id)->count();
    $turn = new RelaTurn($workspace);

    $turn('a');
    $turn('b');

    expect(AgentConversation::query()->where('workspace_id', $workspace->workspace->id)->count())->toBe($baseline + 2);
});

it('reuses the given conversation across invocations', function (): void {
    CrmAssistant::fake(['one', 'two']);
    $workspace = EvalWorkspace::seed();
    $conversation = $workspace->conversation();
    $baseline = AgentConversation::query()->where('workspace_id', $workspace->workspace->id)->count();
    $turn = new RelaTurn($workspace, conversationId: (string) $conversation->getKey());

    $turn('a');
    $turn('b');

    expect(AgentConversation::query()->where('workspace_id', $workspace->workspace->id)->count())->toBe($baseline)
        ->and(AgentConversationMessage::query()->where('conversation_id', $conversation->getKey())->where('role', 'assistant')->count())->toBe(2);
});

it('records a failed sample when the turn throws', function (): void {
    CrmAssistant::fake(fn () => throw new RuntimeException('provider down'));
    $turn = new RelaTurn(EvalWorkspace::seed());

    $envelope = json_decode($turn('anything'), true);

    expect($envelope['error'])->toContain('provider down')
        ->and($envelope['text'])->toBe('')
        ->and($envelope['tool_calls'])->toBe([])
        ->and($envelope['replayed_bytes'])->toBe(0);
});

it('lists the row ids of each tool call in the envelope', function (): void {
    $row = assistantRowWithResults(
        EvalWorkspace::seed(),
        [
            ['id' => 'call_1', 'name' => 'ListCompaniesTool', 'arguments' => ['lookup' => true], 'result' => json_encode(['data' => [['id' => 'a'], ['id' => 'b']], 'display_block' => ['rows' => [1, 2, 3]]])],
            ['id' => 'call_2', 'name' => 'GetCompanyTool', 'arguments' => ['id' => 'a'], 'result' => 'plain text'],
        ],
        ['input_tokens' => 120, 'output_tokens' => 30],
        ['duration_ms' => 450],
    );

    $envelope = RelaTurn::envelopeFromRow($row);

    expect($envelope->toolResultRowIds)->toBe([['a', 'b'], []])
        ->and(array_column($envelope->toolCalls, 'name'))->toBe(['ListCompaniesTool', 'GetCompanyTool'])
        ->and($envelope->toolCalls[0]['arguments'])->toBe(['lookup' => true])
        ->and($envelope->inputTokens)->toBe(120)
        ->and($envelope->outputTokens)->toBe(30)
        ->and($envelope->durationMs)->toBe(450);
});
