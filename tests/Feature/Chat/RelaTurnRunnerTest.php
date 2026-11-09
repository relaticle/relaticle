<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\ToolCall;
use Pest\Evals\Support\ToolCallParser;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AgentConversationMessage;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;
use Tests\Evals\Support\TurnEnvelope;

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

    expect($envelope)->toHaveKeys(['text', 'tool_calls', 'rendered_row_ids', 'replayed_bytes', 'usage', 'duration_ms', 'error'])
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

it('lists the rendered row ids of each tool call in the envelope', function (): void {
    $row = assistantRowWithResults(
        EvalWorkspace::seed(),
        [
            ['id' => 'call_1', 'name' => 'ListCompaniesTool', 'arguments' => ['lookup' => true], 'result' => json_encode(['data' => [['id' => 'c'], ['id' => 'd']]])],
            ['id' => 'call_2', 'name' => 'ListCompaniesTool', 'arguments' => [], 'result' => json_encode(['data' => [['id' => 'a'], ['id' => 'b']], 'display_block' => ['rows' => [['id' => 'a'], ['id' => 'b']]]])],
            ['id' => 'call_3', 'name' => 'GetCompanyTool', 'arguments' => ['id' => 'a'], 'result' => 'plain text'],
        ],
        ['input_tokens' => 120, 'output_tokens' => 30],
        ['duration_ms' => 450],
    );

    $envelope = RelaTurn::envelopeFromRow($row);

    expect($envelope->renderedRowIds)->toBe([[], ['a', 'b'], []])
        ->and(array_column($envelope->toolCalls, 'name'))->toBe(['ListCompaniesTool', 'ListCompaniesTool', 'GetCompanyTool'])
        ->and($envelope->toolCalls[0]['arguments'])->toBe(['lookup' => true])
        ->and($envelope->inputTokens)->toBe(120)
        ->and($envelope->outputTokens)->toBe(30)
        ->and($envelope->durationMs)->toBe(450);
});

it('keeps the retry cause and retries a rate limited turn three times', function (): void {
    Sleep::fake();
    CrmAssistant::fake(fn () => throw RateLimitedException::forProvider('anthropic'));
    $turn = new RelaTurn(EvalWorkspace::seed());

    $envelope = json_decode($turn('anything'), true);

    expect($envelope['error'])->toContain('rate limit')
        ->and($envelope['error'])->toContain('asked for a retry')
        ->and($envelope['text'])->toBe('');

    Sleep::assertSleptTimes(RelaTurn::MAX_RETRIES);
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2)->seconds(), Sleep::for(2)->seconds()]);
});

it('does not retry or sleep when the turn succeeds', function (): void {
    Sleep::fake();
    CrmAssistant::fake(['Fine.']);
    $turn = new RelaTurn(EvalWorkspace::seed());

    $turn('anything');

    Sleep::assertNeverSlept();
});

it('uses a new conversation from the closure on every invocation', function (): void {
    CrmAssistant::fake(['one', 'two']);
    $workspace = EvalWorkspace::seed();
    $created = [];
    $turn = new RelaTurn($workspace, conversation: function () use ($workspace, &$created): string {
        return $created[] = (string) $workspace->conversation()->getKey();
    });

    $turn('a');
    $turn('b');

    $used = AgentConversationMessage::query()->whereIn('conversation_id', $created)->where('role', 'assistant')->pluck('conversation_id')->all();

    expect($created)->toHaveCount(2)
        ->and($created[0])->not->toBe($created[1])
        ->and($used)->toEqualCanonicalizing($created);
});

it('writes unicode and newlines into the envelope without escaping', function (): void {
    $json = new TurnEnvelope("سلام\nشما", [], [], 0, 0, 0, 0, null)->toJson();

    expect($json)->toContain('سلام')
        ->and($json)->toContain('\n')
        ->and(json_decode($json, true)['text'])->toBe("سلام\nشما");
});

it('records a tool call end to end that the plugin parser reads', function (): void {
    CrmAssistant::fake([new ToolCall('call_1', 'GetCrmSummaryTool', []), 'You have a few deals.']);
    $turn = new RelaTurn(EvalWorkspace::seed());

    $json = $turn('How many deals do we have');
    $envelope = json_decode($json, true);
    $calls = ToolCallParser::fromOutput($json);

    expect($envelope['error'])->toBeNull()
        ->and($envelope['tool_calls'][0]['name'])->toBe('GetCrmSummaryTool')
        ->and($envelope['text'])->toBe('You have a few deals.')
        ->and($calls)->toHaveCount(1)
        ->and($calls[0]['name'])->toBe('GetCrmSummaryTool');
});

it('lists the text of every step in the envelope', function (): void {
    $workspace = EvalWorkspace::seed();
    $row = assistantRowWithResults($workspace, []);
    $row->update(['steps' => json_encode([
        ['content' => 'Checking your tasks.', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
        ['content' => 'Done.', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
    ], JSON_THROW_ON_ERROR)]);

    $envelope = RelaTurn::envelopeFromRow($row->refresh());

    expect($envelope->stepsText)->toBe(['Checking your tasks.', 'Done.'])
        ->and(json_decode($envelope->toJson(), true)['steps_text'])->toBe(['Checking your tasks.', 'Done.']);
});

it('resumes the turn the conversation closure names', function (): void {
    CrmAssistant::fake(['Created Acme Robotics.']);
    $workspace = EvalWorkspace::seed();
    $turn = new RelaTurn($workspace, origin: MessageOrigin::Resume, conversation: function () use ($workspace): array {
        return Prior::approvedCompany($workspace);
    });

    $turn('The user decided the proposals above.');

    CrmAssistant::assertPrompted(fn ($prompt): bool => $prompt->agent->resolvedActions !== []
        && $prompt->agent->resolvedActions[0]['just_decided'] === true
        && str_contains($prompt->prompt, 'The user decided the proposals above:')
        && str_contains($prompt->prompt, 'Acme Robotics'));
});
