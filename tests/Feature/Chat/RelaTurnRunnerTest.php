<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Pest\Evals\Support\ToolCallParser;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\Chat\Services\ModelRegistry;
use Tests\Evals\Support\ClaudeCode\ClaudeCodeProvider;
use Tests\Evals\Support\ClaudeCode\HistoryTranscript;
use Tests\Evals\Support\ClaudeCode\StreamJson;
use Tests\Evals\Support\ClaudeCode\ToolRendezvous;
use Tests\Evals\Support\EvalModels;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;
use Tests\Evals\Support\Splits;
use Tests\Evals\Support\TurnEnvelope;

/**
 * @return list<Message>
 */
function priorTurnWithOneToolCall(): array
{
    return [
        new UserMessage('List my companies'),
        new AssistantMessage('', collect([new ToolCall('t1', 'ListCompaniesTool', ['limit' => 5], 't1')])),
        new ToolResultMessage(collect([new ToolResult('t1', 'ListCompaniesTool', ['limit' => 5], '{"data":[]}')])),
        new AssistantMessage('You have no companies yet.'),
    ];
}

/**
 * @return list<string>
 */
function listedEvalCases(?string $group = null): array
{
    $command = [PHP_BINARY, '-d', 'memory_limit=1G', 'vendor/bin/pest', 'tests/Evals', '--list-tests', '--no-tia'];

    if ($group !== null) {
        $command[] = '--group='.$group;
    }

    $output = Process::path(base_path())->run($command)->throw()->output();

    $lines = array_values(array_filter(explode("\n", $output), fn (string $line): bool => str_starts_with($line, ' - ')));
    $cases = array_map(fn (string $line): string => Str::before(substr($line, 3), '"'), $lines);

    expect($lines)->not->toBeEmpty()
        ->and(array_filter($cases, fn (string $case): bool => ! str_contains($case, '::__pest_evaluable_')))->toBe([]);

    return array_values(array_unique($cases));
}

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

it('reads the served model, cache legs, reasoning and step count from the stored row', function (): void {
    $row = assistantRowWithResults(
        EvalWorkspace::seed(),
        [],
        ['input_tokens' => 900, 'output_tokens' => 40, 'cache_read_input_tokens' => 600, 'cache_write_input_tokens' => 200, 'reasoning_tokens' => 12],
        ['duration_ms' => 450, 'model' => 'claude-sonnet-5'],
    );

    $envelope = RelaTurn::envelopeFromRow($row);
    $json = $envelope->toTrace();

    expect($envelope->servedModel)->toBe('claude-sonnet-5')
        ->and($envelope->cacheReadTokens)->toBe(600)
        ->and($envelope->cacheWriteTokens)->toBe(200)
        ->and($envelope->reasoningTokens)->toBe(12)
        ->and($envelope->steps)->toBe(1)
        ->and($envelope->retries)->toBe(0)
        ->and($json['served_model'])->toBe('claude-sonnet-5')
        ->and($json['usage']['cache_read'])->toBe(600);
});

it('keeps the retry cause and retries a rate limited turn three times', function (): void {
    Sleep::fake();
    CrmAssistant::fake(fn () => throw RateLimitedException::forProvider('anthropic'));
    $turn = new RelaTurn(EvalWorkspace::seed());

    $envelope = json_decode($turn('anything'), true);

    expect($envelope['error'])->toContain('rate limit')
        ->and($envelope['error'])->toContain('asked for a retry')
        ->and($envelope['text'])->toBe('')
        ->and($turn->envelope()->retries)->toBe(RelaTurn::MAX_RETRIES);

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

it('accepts a known alias and rejects an unknown substitution', function (): void {
    EvalModels::assertServed('gpt-5.5', 'gpt-6-luna');
    EvalModels::assertServed('gpt-5.5', '');

    expect(fn () => EvalModels::assertServed('claude-sonnet-5-5', 'claude-sonnet-5'))
        ->toThrow(RuntimeException::class, 'Asked for claude-sonnet-5-5, served by claude-sonnet-5');
});

it('counts an extra attempt after a rate limited first attempt', function (): void {
    Sleep::fake();
    $calls = 0;
    CrmAssistant::fake(function () use (&$calls): string {
        if ($calls++ === 0) {
            throw RateLimitedException::forProvider('anthropic');
        }

        return 'Fine.';
    });
    $turn = new RelaTurn(EvalWorkspace::seed());

    $turn('anything');

    expect($turn->envelope()->error)->toBeNull()
        ->and($turn->envelope()->text)->toBe('Fine.')
        ->and($turn->envelope()->retries)->toBe(1);
});

it('counts the extra attempt of a turn that retries and then throws', function (): void {
    Sleep::fake();
    $calls = 0;
    CrmAssistant::fake(function () use (&$calls): string {
        if ($calls++ === 0) {
            throw RateLimitedException::forProvider('anthropic');
        }

        throw new RuntimeException('The provider broke.');
    });
    $turn = new RelaTurn(EvalWorkspace::seed());

    $turn('anything');

    expect($turn->envelope()->error)->toContain('The provider broke.')
        ->and($turn->envelope()->retries)->toBe(1);
});

it('reads a stored error row as a failed turn', function (): void {
    $row = assistantRowWithResults(
        EvalWorkspace::seed(),
        [],
        [],
        ['error' => true, 'failure' => ['class' => 'Laravel\\Ai\\Exceptions\\RateLimitedException', 'status' => 429]],
    );

    $envelope = RelaTurn::envelopeFromRow($row);

    expect($envelope->error)->toContain('RateLimitedException')
        ->and($envelope->text)->toBe('Done.');
});

it('fails loudly when the provider served another model, and still records the turn', function (): void {
    CrmAssistant::fake(['Fine.']);
    RelaTurn::forget();
    $turn = new RelaTurn(EvalWorkspace::seed(), model: 'claude-opus-5');
    $rewriting = false;
    DB::listen(function (QueryExecuted $query) use (&$rewriting): void {
        if ($rewriting || ! str_starts_with($query->sql, 'insert into "agent_conversation_messages"')) {
            return;
        }

        $rewriting = true;
        DB::table('agent_conversation_messages')->where('role', 'assistant')->update(['meta' => json_encode(['model' => 'claude-sonnet-5'])]);
        $rewriting = false;
    });
    $served = null;

    try {
        $turn('anything');
    } catch (RuntimeException $e) {
        $served = $e->getMessage();
    }

    expect($served)->toBe('Asked for claude-opus-5, served by claude-sonnet-5')
        ->and(RelaTurn::$last)->toBeInstanceOf(TurnEnvelope::class);
});

it('resolves a candidate model the catalog does not offer', function (): void {
    expect(resolve(ModelRegistry::class)->find('claude-sonnet-5-5'))->toBeNull();

    EvalModels::register();

    expect(resolve(ModelRegistry::class)->find('claude-sonnet-5-5')?->provider)->toBe('anthropic')
        ->and(resolve(ModelRegistry::class)->find('gpt-6-luna')?->provider)->toBe('openai')
        ->and(resolve(ModelRegistry::class)->find('claude-sonnet-5')?->provider)->toBe('anthropic');
});

it('refuses an unknown effort before any turn runs', function (): void {
    expect(fn () => EvalModels::effortFrom('extreme'))->toThrow(RuntimeException::class);
});

it('keeps the configured effort when none is asked for and applies a valid one', function (): void {
    $configured = config('chat.anthropic_effort');

    expect(EvalModels::effortFrom(null))->toBeNull()
        ->and(EvalModels::effortFrom(''))->toBeNull()
        ->and(EvalModels::effortFrom('xhigh'))->toBe('xhigh');

    EvalModels::register('low');

    expect(config('chat.anthropic_effort'))->toBe('low');

    config()->set('chat.anthropic_effort', $configured);
    EvalModels::register(null);

    expect(config('chat.anthropic_effort'))->toBe($configured);
});

it('puts every eval case in exactly one split', function (): void {
    $cases = listedEvalCases();
    $listed = [];

    foreach (Splits::NAMES as $split) {
        foreach (listedEvalCases($split) as $case) {
            $listed[$case][] = $split;
        }
    }

    expect($cases)->not->toBeEmpty();

    Splits::assertGrouped(collect($cases)->mapWithKeys(fn (string $case): array => [$case => $listed[$case] ?? []])->all());
});

it('names the case that has no split or two of them', function (): void {
    expect(fn () => Splits::assertGrouped(['Rela::first' => ['train'], 'Rela::second' => []]))
        ->toThrow(RuntimeException::class, 'Eval case [Rela::second] needs exactly one of [train, test, guard], found none')
        ->and(fn () => Splits::assertGrouped(['Rela::third' => ['train', 'guard']]))
        ->toThrow(RuntimeException::class, 'Eval case [Rela::third] needs exactly one of [train, test, guard], found train and guard')
        ->and(fn () => Splits::assertGrouped(['Rela::fourth' => ['train', 'slow']]))
        ->not->toThrow(RuntimeException::class);
});

it('reads one assistant message at a time from a claude code stream', function (): void {
    $lines = [
        '{"type":"system","subtype":"init","model":"claude-sonnet-5","tools":["mcp__rela__CreateCompanyTool"]}',
        '{"type":"assistant","message":{"model":"claude-sonnet-5","content":[{"type":"tool_use","id":"t1","name":"mcp__rela__CreateCompanyTool","input":{"records":[{"name":"Northwind Traders"}]}}],"usage":{"input_tokens":2,"cache_creation_input_tokens":1396,"cache_read_input_tokens":0,"output_tokens":30}}}',
        '{"type":"user","message":{"content":[{"type":"tool_result","tool_use_id":"t1","content":[{"type":"text","text":"{}"}]}]}}',
        '{"type":"assistant","message":{"model":"claude-sonnet-5","content":[{"type":"text","text":"Review the proposal below."}],"usage":{"input_tokens":2,"cache_creation_input_tokens":194,"cache_read_input_tokens":1396,"output_tokens":9}}}',
        '{"type":"result","subtype":"success","is_error":false,"num_turns":2,"duration_ms":4735}',
    ];

    $first = StreamJson::step($lines[1]);
    $second = StreamJson::step($lines[3]);

    expect($first->toolCalls)->toHaveCount(1)
        ->and($first->toolCalls[0]->name)->toBe('CreateCompanyTool')
        ->and($first->toolCalls[0]->id)->toBe('t1')
        ->and($first->toolCalls[0]->arguments)->toBe(['records' => [['name' => 'Northwind Traders']]])
        ->and($first->finishReason)->toBe(FinishReason::ToolCalls)
        ->and($first->meta->model)->toBe('claude-sonnet-5')
        ->and($second->text)->toBe('Review the proposal below.')
        ->and($second->finishReason)->toBe(FinishReason::Stop)
        ->and($second->usage->cacheReadInputTokens)->toBe(1396)
        ->and(StreamJson::isEnd($lines[4]))->toBeTrue()
        ->and(StreamJson::isEnd($lines[2]))->toBeFalse();
});

it('merges the lines one claude code message arrives in', function (): void {
    $thinking = '{"type":"assistant","message":{"id":"m1","model":"claude-sonnet-5","content":[{"type":"thinking","thinking":"Two records."}],"usage":{"input_tokens":2,"output_tokens":5}}}';
    $text = '{"type":"assistant","message":{"id":"m1","model":"claude-sonnet-5","content":[{"type":"text","text":"Checking."}],"usage":{"input_tokens":2,"output_tokens":9}}}';
    $call = '{"type":"assistant","message":{"id":"m1","model":"claude-sonnet-5","content":[{"type":"tool_use","id":"t9","name":"mcp__rela__ListCompaniesTool","input":{}}],"usage":{"input_tokens":2,"output_tokens":20}}}';

    $step = StreamJson::step($thinking, $text, $call);

    expect(StreamJson::messageId($call))->toBe('m1')
        ->and($step->text)->toBe('Checking.')
        ->and($step->reasoning)->toBe('Two records.')
        ->and($step->toolCalls)->toHaveCount(1)
        ->and($step->toolCalls[0]->arguments)->toBe([])
        ->and($step->usage->outputTokens)->toBe(20)
        ->and($step->finishReason)->toBe(FinishReason::ToolCalls);
});

it('renders an empty history as nothing and a tool turn in order', function (): void {
    expect(HistoryTranscript::render([]))->toBe('');

    $text = HistoryTranscript::render(priorTurnWithOneToolCall());

    expect($text)->toMatch('/User: .*\nRela called ListCompaniesTool\(.*\)\nTool result: .*\nRela: /s')
        ->and($text)->toContain('{"limit":5}')
        ->and($text)->toContain('{"data":[]}');
});

it('answers a tool call from the stub through the rendezvous socket', function (): void {
    $directory = sys_get_temp_dir().'/ccs'.bin2hex(random_bytes(3));
    mkdir($directory);
    $schemas = $directory.'/tools.json';
    file_put_contents($schemas, json_encode([[
        'name' => 'ListCompaniesTool',
        'description' => 'Lists companies',
        'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]],
    ]]));
    $rendezvous = ToolRendezvous::listen($directory);

    $input = implode("\n", [
        '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}',
        '{"jsonrpc":"2.0","method":"notifications/initialized"}',
        '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
        '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"ListCompaniesTool","arguments":{"limit":5}}}',
    ])."\n";

    $process = Process::forever()->input($input)->start([PHP_BINARY, base_path('tests/Evals/bin/claude-tool-stub.php'), $schemas, $rendezvous->path()]);

    $call = null;

    while ($call === null) {
        $process->running();
        $call = $rendezvous->accept(0.1);
    }

    $rendezvous->reply('{"data":["Company 01"]}', false);
    $result = $process->wait();
    $rendezvous->close();

    $replies = array_map(fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", $result->output()))));
    File::deleteDirectory($directory);

    expect($call)->toBe(['name' => 'ListCompaniesTool', 'arguments' => ['limit' => 5]])
        ->and($replies)->toHaveCount(3)
        ->and($replies[0]['result']['capabilities'])->toHaveKey('tools')
        ->and($replies[1]['result']['tools'][0]['name'])->toBe('ListCompaniesTool')
        ->and($replies[1]['result']['tools'][0]['inputSchema']['type'])->toBe('object')
        ->and($replies[2]['id'])->toBe(3)
        ->and($replies[2]['result']['content'][0]['text'])->toBe('{"data":["Company 01"]}')
        ->and($replies[2]['result']['isError'])->toBeFalse();
});

it('points every claude model at the claude code provider under the plan runner', function (): void {
    EvalModels::register(null, 'plan');

    $registry = resolve(ModelRegistry::class);

    expect($registry->find('claude-sonnet-5')?->provider)->toBe('claude-code')
        ->and($registry->find('claude-haiku-5-5')?->provider)->toBe('claude-code')
        ->and($registry->find('gpt-6-luna')?->provider)->toBe('openai')
        ->and(config('ai.providers.claude-code.driver'))->toBe('claude-code')
        ->and(resolve(AiManager::class)->textProvider('claude-code'))->toBeInstanceOf(ClaudeCodeProvider::class)
        ->and(fn () => EvalModels::register(null, 'cloud'))->toThrow(RuntimeException::class, 'PEST_EVALS_RUNNER must be one of api, plan');
});
