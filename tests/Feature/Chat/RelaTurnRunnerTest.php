<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Pest\Evals\Support\ToolCallParser;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\Chat\Services\ModelRegistry;
use Tests\Evals\Support\ClaudeCode\ClaudeCodeGateway;
use Tests\Evals\Support\ClaudeCode\ClaudeCodeProcess;
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
    $file = tempnam(sys_get_temp_dir(), 'eval-cases');
    $command = [PHP_BINARY, '-d', 'memory_limit=1G', 'vendor/bin/pest', 'tests/Evals', '--list-tests-xml', $file, '--no-tia'];

    if ($group !== null) {
        $command[] = '--group='.$group;
    }

    Process::path(base_path())->run($command)->throw();

    $cases = array_map(
        fn (SimpleXMLElement $method): string => (string) $method['id'],
        simplexml_load_file($file)->xpath('//*[local-name()="testMethod"]') ?: [],
    );
    unlink($file);

    expect($cases)->not->toBeEmpty()
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

it('does not run a later turn of a conversation whose earlier turn failed', function (): void {
    RelaTurn::forget();
    CrmAssistant::fake(fn () => throw new RuntimeException('provider down'));
    $workspace = EvalWorkspace::seed();
    $turn = new RelaTurn($workspace, conversationId: (string) $workspace->conversation()->getKey());

    $turn('first');

    expect(fn (): string => $turn('second'))->toThrow(RuntimeException::class, 'turn 1 failed: provider down')
        ->and(RelaTurn::$turns)->toHaveCount(1)
        ->and(RelaTurn::$skipped)->toBe(1);

    RelaTurn::forget();
});

it('keeps running independent samples after one of them failed', function (): void {
    RelaTurn::forget();
    CrmAssistant::fake(fn () => throw new RuntimeException('provider down'));
    $turn = new RelaTurn(EvalWorkspace::seed());

    $turn('first');
    $turn('second');

    expect(RelaTurn::$turns)->toHaveCount(2)
        ->and(RelaTurn::$skipped)->toBe(0);

    RelaTurn::forget();
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
    $turn = new RelaTurn(EvalWorkspace::seed(), model: 'claude-opus-5-5');
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

    expect($served)->toBe('Asked for claude-opus-5-5, served by claude-sonnet-5')
        ->and(RelaTurn::$last)->toBeInstanceOf(TurnEnvelope::class);
});

it('resolves a candidate model the catalog does not offer', function (): void {
    expect(resolve(ModelRegistry::class)->find('claude-haiku-5-5'))->toBeNull();

    EvalModels::register();

    expect(resolve(ModelRegistry::class)->find('claude-haiku-5-5')?->provider)->toBe('anthropic')
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

function stubSession(): array
{
    $directory = sys_get_temp_dir().'/ccs'.bin2hex(random_bytes(3));
    mkdir($directory);
    $schemas = $directory.'/tools.json';
    file_put_contents($schemas, json_encode([[
        'name' => 'ListCompaniesTool',
        'description' => 'Lists companies',
        'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]],
    ]]));

    return [$directory, $schemas, ToolRendezvous::listen($directory)];
}

function waitForStubCalls(ToolRendezvous $rendezvous, InvokedProcess $process, int $count): void
{
    $deadline = microtime(true) + 20;

    while (count($rendezvous->waiting()) < $count) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The stub never reached the rendezvous.');
        }

        if (! $process->running()) {
            throw new RuntimeException('The stub exited: '.$process->errorOutput());
        }

        $rendezvous->accept(0.1);
    }
}

function stubReplies(string $output): array
{
    return array_map(fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", $output))));
}

it('answers a tool call from the stub through the rendezvous socket', function (): void {
    [$directory, $schemas, $rendezvous] = stubSession();

    $input = implode("\n", [
        '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}',
        '{"jsonrpc":"2.0","method":"notifications/initialized"}',
        '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
        '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"ListCompaniesTool","arguments":{"limit":5},"_meta":{"claudecode/toolUseId":"toolu_1"}}}',
    ])."\n";

    $process = Process::forever()->input($input)->start([PHP_BINARY, base_path('tests/Evals/bin/claude-tool-stub.php'), $schemas, $rendezvous->path()]);

    waitForStubCalls($rendezvous, $process, 1);
    $call = $rendezvous->waiting()[0];
    $rendezvous->reply('{"data":["Company 01"]}', false);
    $result = $process->wait();
    $rendezvous->close();

    $replies = stubReplies($result->output());
    File::deleteDirectory($directory);

    expect($call)->toBe(['id' => 'toolu_1', 'name' => 'ListCompaniesTool', 'arguments' => ['limit' => 5]])
        ->and($replies)->toHaveCount(3)
        ->and($replies[0]['result']['capabilities'])->toHaveKey('tools')
        ->and($replies[1]['result']['tools'][0]['name'])->toBe('ListCompaniesTool')
        ->and($replies[1]['result']['tools'][0]['inputSchema']['type'])->toBe('object')
        ->and($replies[2]['id'])->toBe(3)
        ->and($replies[2]['result']['content'][0]['text'])->toBe('{"data":["Company 01"]}')
        ->and($replies[2]['result']['isError'])->toBeFalse();
});

it('lets the stub hold two calls at once and answer them in either order', function (): void {
    [$directory, $schemas, $rendezvous] = stubSession();

    $input = implode("\n", [
        '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"ListCompaniesTool","arguments":{"limit":1},"_meta":{"claudecode/toolUseId":"toolu_a"}}}',
        '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"ListCompaniesTool","arguments":{"limit":2},"_meta":{"claudecode/toolUseId":"toolu_b"}}}',
    ])."\n";

    $process = Process::forever()->input($input)->start([PHP_BINARY, base_path('tests/Evals/bin/claude-tool-stub.php'), $schemas, $rendezvous->path()]);

    waitForStubCalls($rendezvous, $process, 2);
    $rendezvous->reply('B', false, $rendezvous->indexOf('toolu_b', 'ListCompaniesTool', ['limit' => 2]));
    $rendezvous->reply('A', true, $rendezvous->indexOf('toolu_a', 'ListCompaniesTool', ['limit' => 1]));
    $replies = stubReplies($process->wait()->output());
    $rendezvous->close();
    File::deleteDirectory($directory);

    $byId = array_column($replies, 'result', 'id');

    expect($byId)->toHaveCount(2)
        ->and($byId[4]['content'][0]['text'])->toBe('B')
        ->and($byId[4]['isError'])->toBeFalse()
        ->and($byId[3]['content'][0]['text'])->toBe('A')
        ->and($byId[3]['isError'])->toBeTrue();
});

it('fails the stub test loudly when the stub exits instead of waiting forever', function (): void {
    [$directory, , $rendezvous] = stubSession();
    $process = Process::forever()->start([PHP_BINARY, '-r', 'exit(3);']);

    expect(fn () => waitForStubCalls($rendezvous, $process, 1))->toThrow(RuntimeException::class, 'The stub exited');

    $rendezvous->close();
    File::deleteDirectory($directory);
});

function isolatedTempRoot(bool $forget = false): ?string
{
    static $base = null;

    if ($forget) {
        $base !== null && File::deleteDirectory($base);
        $base = null;
        ClaudeCodeProcess::$tempRoot = null;

        return null;
    }

    if ($base === null) {
        $base = sys_get_temp_dir().'/fct'.bin2hex(random_bytes(4));
        mkdir($base.'/root', 0700, true);
        ClaudeCodeProcess::$tempRoot = $base.'/root';
    }

    return $base;
}

function fakeClaudeScenario(array $steps): string
{
    $path = isolatedTempRoot().'/scenario'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode(['steps' => $steps], JSON_THROW_ON_ERROR));
    ClaudeCodeProcess::$command = [PHP_BINARY, base_path('tests/Evals/bin/fake-claude.php'), $path];

    return $path;
}

function fakeClaudeRecord(string $scenario): array
{
    return json_decode((string) file_get_contents($scenario.'.record'), true, flags: JSON_THROW_ON_ERROR);
}

function startFakeClaude(string ...$tools): ClaudeCodeProcess
{
    return ClaudeCodeProcess::start('claude-sonnet-5', 'high', 'You are Rela.', 'hello', array_map(fn (string $name): array => [
        'name' => $name,
        'description' => $name,
        'inputSchema' => ['type' => 'object'],
    ], $tools));
}

function toolMessage(string $id, array $calls, ?string $text = null): array
{
    return ['do' => 'message', 'id' => $id, 'stop' => 'tool_use', 'blocks' => [
        ...($text === null ? [] : [['type' => 'text', 'text' => $text]]),
        ...array_map(fn (array $call): array => ['type' => 'tool_use', 'id' => $call[0], 'name' => 'mcp__rela__'.$call[1], 'input' => $call[2]], $calls),
    ]];
}

function textMessage(string $id, string $text): array
{
    return ['do' => 'message', 'id' => $id, 'blocks' => [['type' => 'text', 'text' => $text]]];
}

afterEach(function (): void {
    ClaudeCodeProcess::$command = ClaudeCodeProcess::DEFAULT_COMMAND;
    ClaudeCodeGateway::releaseAll();
    isolatedTempRoot(forget: true);
});

it('hands one tool call to laravel ai and resumes the model with its result', function (): void {
    $scenario = fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_1', 'ListCompaniesTool', ['limit' => 5]]], 'Checking.'),
        ['do' => 'connect', 'key' => 'k1', 'id' => 'toolu_1', 'name' => 'ListCompaniesTool', 'arguments' => ['limit' => 5]],
        ['do' => 'await', 'keys' => ['k1']],
        ['do' => 'user_ok', 'id' => 'toolu_1'],
        textMessage('m2', 'Done.'),
        ['do' => 'result'],
    ]);
    $process = startFakeClaude('ListCompaniesTool');

    $first = $process->nextStep();
    $process->deliver([new ToolResult('toolu_1', 'ListCompaniesTool', ['limit' => 5], '{"data":[]}')]);
    $second = $process->nextStep();
    $record = fakeClaudeRecord($scenario);

    expect($first->text)->toBe('Checking.')
        ->and($first->toolCalls)->toHaveCount(1)
        ->and($first->toolCalls[0]->id)->toBe('toolu_1')
        ->and($first->finishReason)->toBe(FinishReason::ToolCalls)
        ->and($first->usage->inputTokens)->toBe(32)
        ->and($first->usage->outputTokens)->toBe(9)
        ->and($second->text)->toBe('Done.')
        ->and($second->finishReason)->toBe(FinishReason::Stop)
        ->and($process->result()['total_cost_usd'])->toBe(0.01)
        ->and($record['replies'])->toBe([['key' => 'k1', 'text' => '{"data":[]}', 'is_error' => false]])
        ->and($record['claudecode_set'])->toBeFalse()
        ->and($record['api_key_set'])->toBeFalse()
        ->and($record['tool_search'])->toBe('false')
        ->and($record['arguments'])->toContain('--include-partial-messages', '--strict-mcp-config', '--no-session-persistence')
        ->and(json_decode($record['first_input'], true)['message']['content'][0]['text'])->toBe('hello');

    $process->release();
});

it('reads a message with no content blocks as an empty reply from its model', function (): void {
    fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_1', 'ListCompaniesTool', ['limit' => 5]]]),
        ['do' => 'connect', 'key' => 'k1', 'id' => 'toolu_1', 'name' => 'ListCompaniesTool', 'arguments' => ['limit' => 5]],
        ['do' => 'await', 'keys' => ['k1']],
        ['do' => 'user_ok', 'id' => 'toolu_1'],
        ['do' => 'message', 'id' => 'm2', 'model' => 'claude-haiku-5-5', 'blocks' => []],
        ['do' => 'result'],
    ]);
    $process = startFakeClaude('ListCompaniesTool');

    $process->nextStep();
    $process->deliver([new ToolResult('toolu_1', 'ListCompaniesTool', ['limit' => 5], '{"data":[]}')]);
    $empty = $process->nextStep();

    expect($empty->text)->toBe('')
        ->and($empty->toolCalls)->toBe([])
        ->and($empty->finishReason)->toBe(FinishReason::Stop)
        ->and($empty->meta->model)->toBe('claude-haiku-5-5');

    $process->release();
});

it('keeps parallel calls of one message in one step and matches each result by tool use id', function (): void {
    $scenario = fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_a', 'ListCompaniesTool', ['limit' => 1]], ['toolu_b', 'ListCompaniesTool', ['limit' => 2]]]),
        ['do' => 'connect', 'key' => 'kb', 'id' => 'toolu_b', 'name' => 'ListCompaniesTool', 'arguments' => ['limit' => 2]],
        ['do' => 'connect', 'key' => 'ka', 'id' => 'toolu_a', 'name' => 'ListCompaniesTool', 'arguments' => ['limit' => 1]],
        ['do' => 'await', 'keys' => ['ka', 'kb']],
        textMessage('m2', 'Both read.'),
        ['do' => 'result'],
    ]);
    $process = startFakeClaude('ListCompaniesTool');

    $first = $process->nextStep();
    $process->deliver([
        new ToolResult('toolu_a', 'ListCompaniesTool', ['limit' => 1], 'ONE'),
        new ToolResult('toolu_b', 'ListCompaniesTool', ['limit' => 2], 'TWO'),
    ]);
    $second = $process->nextStep();
    $replies = collect(fakeClaudeRecord($scenario)['replies'])->pluck('text', 'key')->all();

    expect(array_map(fn (ToolCall $call): string => $call->id, $first->toolCalls))->toBe(['toolu_a', 'toolu_b'])
        ->and($replies)->toEqual(['ka' => 'ONE', 'kb' => 'TWO'])
        ->and($second->text)->toBe('Both read.');

    $process->release();
});

it('keeps serial calls in one step when claude code connects the second only after the first is answered', function (): void {
    $scenario = fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_a', 'ListCompaniesTool', ['limit' => 1]], ['toolu_b', 'ListCompaniesTool', ['limit' => 2]]]),
        ['do' => 'connect', 'key' => 'ka', 'id' => 'toolu_a', 'name' => 'ListCompaniesTool', 'arguments' => ['limit' => 1]],
        ['do' => 'await', 'keys' => ['ka']],
        ['do' => 'user_ok', 'id' => 'toolu_a'],
        ['do' => 'connect', 'key' => 'kb', 'id' => 'toolu_b', 'name' => 'ListCompaniesTool', 'arguments' => ['limit' => 2]],
        ['do' => 'await', 'keys' => ['kb']],
        textMessage('m2', 'Both read.'),
        ['do' => 'result'],
    ]);
    $process = startFakeClaude('ListCompaniesTool');

    $first = $process->nextStep();
    $process->deliver([
        new ToolResult('toolu_a', 'ListCompaniesTool', ['limit' => 1], 'ONE'),
        new ToolResult('toolu_b', 'ListCompaniesTool', ['limit' => 2], 'TWO'),
    ]);
    $second = $process->nextStep();

    expect($first->toolCalls)->toHaveCount(2)
        ->and(collect(fakeClaudeRecord($scenario)['replies'])->pluck('text', 'key')->all())->toBe(['ka' => 'ONE', 'kb' => 'TWO'])
        ->and($second->text)->toBe('Both read.');

    $process->release();
});

it('skips the result of a call claude code refused only after it handed the step over', function (): void {
    fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_a', 'ListCompaniesTool', []], ['toolu_b', 'ListCompaniesTool', ['limit' => 9]]]),
        ['do' => 'connect', 'key' => 'ka', 'id' => 'toolu_a', 'name' => 'ListCompaniesTool', 'arguments' => []],
        ['do' => 'await', 'keys' => ['ka']],
        ['do' => 'user_ok', 'id' => 'toolu_a'],
        ['do' => 'user_error', 'id' => 'toolu_b'],
        textMessage('m2', 'Done.'),
        ['do' => 'result'],
    ]);
    $process = startFakeClaude('ListCompaniesTool');

    $first = $process->nextStep();
    $process->deliver([
        new ToolResult('toolu_a', 'ListCompaniesTool', [], 'ONE'),
        new ToolResult('toolu_b', 'ListCompaniesTool', ['limit' => 9], 'TWO'),
    ]);

    expect($first->toolCalls)->toHaveCount(2)
        ->and($process->rejected())->toBe([['id' => 'toolu_b', 'name' => 'ListCompaniesTool']])
        ->and($process->nextStep()->text)->toBe('Done.');

    $process->release();
});

it('counts a stub connection that carries no tool use id when its name and arguments match', function (): void {
    fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_1', 'ListCompaniesTool', ['limit' => 5]]]),
        ['do' => 'connect', 'key' => 'k1', 'name' => 'ListCompaniesTool', 'arguments' => ['limit' => 5]],
        ['do' => 'await', 'keys' => ['k1']],
        textMessage('m2', 'Done.'),
        ['do' => 'result'],
    ]);
    $process = startFakeClaude('ListCompaniesTool');
    $started = microtime(true);

    $first = $process->nextStep();
    $process->deliver([new ToolResult('toolu_1', 'ListCompaniesTool', ['limit' => 5], 'ONE')]);

    expect(microtime(true) - $started)->toBeLessThan(10)
        ->and($first->toolCalls)->toHaveCount(1)
        ->and($process->nextStep()->text)->toBe('Done.');

    $process->release();
});

it('answers serial calls that connect in the reverse of the order laravel ai returns them', function (): void {
    $scenario = fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_a', 'ListCompaniesTool', ['limit' => 1]], ['toolu_b', 'ListCompaniesTool', ['limit' => 2]]]),
        ['do' => 'connect', 'key' => 'kb', 'id' => 'toolu_b', 'name' => 'ListCompaniesTool', 'arguments' => ['limit' => 2]],
        ['do' => 'await', 'keys' => ['kb']],
        ['do' => 'user_ok', 'id' => 'toolu_b'],
        ['do' => 'connect', 'key' => 'ka', 'id' => 'toolu_a', 'name' => 'ListCompaniesTool', 'arguments' => ['limit' => 1]],
        ['do' => 'await', 'keys' => ['ka']],
        textMessage('m2', 'Both read.'),
        ['do' => 'result'],
    ]);
    $process = startFakeClaude('ListCompaniesTool');
    $started = microtime(true);

    $process->nextStep();
    $process->deliver([
        new ToolResult('toolu_a', 'ListCompaniesTool', ['limit' => 1], 'ONE'),
        new ToolResult('toolu_b', 'ListCompaniesTool', ['limit' => 2], 'TWO'),
    ]);

    $final = $process->nextStep();

    expect(microtime(true) - $started)->toBeLessThan(10)
        ->and($final->text)->toBe('Both read.')
        ->and(collect(fakeClaudeRecord($scenario)['replies'])->pluck('text', 'key')->all())->toBe(['kb' => 'TWO', 'ka' => 'ONE']);

    $process->release();
});

it('fails fast with claude code own error when it writes one and exits while the step waits for a call', function (): void {
    fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_1', 'ListCompaniesTool', []]]),
        ['do' => 'result_error', 'text' => 'You have hit your usage limit'],
        ['do' => 'exit', 'code' => 1],
    ]);
    $process = startFakeClaude('ListCompaniesTool');
    $started = microtime(true);

    expect(fn () => $process->nextStep())->toThrow(RuntimeException::class, 'Claude Code failed: You have hit your usage limit')
        ->and(microtime(true) - $started)->toBeLessThan(10);

    $process->release();
});

it('fails fast with claude code own error when it exits while deliver waits for a connection', function (): void {
    fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_1', 'ListCompaniesTool', []]]),
        ['do' => 'connect', 'key' => 'k1', 'id' => 'toolu_1', 'name' => 'ListCompaniesTool', 'arguments' => []],
        ['do' => 'pause', 'seconds' => 1],
        ['do' => 'result_error', 'text' => 'You have hit your usage limit'],
        ['do' => 'exit', 'code' => 1],
    ]);
    $process = startFakeClaude('ListCompaniesTool');
    $started = microtime(true);
    $process->nextStep();

    expect(fn () => $process->deliver([new ToolResult('toolu_zzz', 'ListCompaniesTool', ['x' => 1], 'ONE')]))->toThrow(RuntimeException::class, 'Claude Code failed: You have hit your usage limit')
        ->and(microtime(true) - $started)->toBeLessThan(10);

    $process->release();
});

it('reports a silent exit as an exit and not as a wait that timed out', function (): void {
    fakeClaudeScenario([['do' => 'init'], toolMessage('m1', [['toolu_1', 'ListCompaniesTool', []]]), ['do' => 'exit', 'code' => 2]]);
    $process = startFakeClaude('ListCompaniesTool');

    expect(fn () => $process->nextStep())->toThrow(RuntimeException::class, 'Claude Code exited before the turn ended');

    $process->release();
});

it('does not hand laravel ai a tool call claude code rejected itself', function (): void {
    fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_1', 'ListCompaniesTool', []], ['toolu_2', 'ListCompaniesTool', ['limit' => 9]]]),
        ['do' => 'user_error', 'id' => 'toolu_2'],
        ['do' => 'connect', 'key' => 'k1', 'id' => 'toolu_1', 'name' => 'ListCompaniesTool', 'arguments' => []],
        ['do' => 'await', 'keys' => ['k1']],
        textMessage('m2', 'Done.'),
        ['do' => 'result'],
    ]);
    $process = startFakeClaude('ListCompaniesTool');

    $first = $process->nextStep();

    expect(array_map(fn (ToolCall $call): string => $call->id, $first->toolCalls))->toBe(['toolu_1'])
        ->and($process->rejected())->toBe([['id' => 'toolu_2', 'name' => 'ListCompaniesTool']]);

    $process->release();
});

it('folds a message whose only calls were rejected into the message that follows', function (): void {
    fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_1', 'ListCompaniesTool', []]], 'Trying.'),
        ['do' => 'user_error', 'id' => 'toolu_1'],
        textMessage('m2', 'I could not read them.'),
        ['do' => 'result'],
    ]);
    $process = startFakeClaude('ListCompaniesTool');

    $step = $process->nextStep();

    expect($step->toolCalls)->toBe([])
        ->and($step->finishReason)->toBe(FinishReason::Stop)
        ->and($step->text)->toBe("Trying.\n\nI could not read them.")
        ->and($step->usage->outputTokens)->toBe(18)
        ->and($process->rejected())->toHaveCount(1)
        ->and($process->result())->not->toBeNull();

    $process->release();
});

it('cleans up the process, the socket and the directory when laravel ai ends the turn early', function (): void {
    $scenario = fakeClaudeScenario([
        ['do' => 'init'],
        toolMessage('m1', [['toolu_1', 'ListCompaniesTool', []]]),
        ['do' => 'connect', 'key' => 'k1', 'id' => 'toolu_1', 'name' => 'ListCompaniesTool', 'arguments' => []],
        ['do' => 'await', 'keys' => ['k1']],
    ]);
    $process = startFakeClaude('ListCompaniesTool');
    $process->nextStep();
    $directory = $process->directory();
    $pid = fakeClaudeRecord($scenario)['pid'];

    expect($directory)->toBeDirectory()
        ->and(file_exists($directory.'/s'))->toBeTrue();

    $process->release();
    $process->release();

    expect($directory)->not->toBeDirectory()
        ->and(Process::run(['kill', '-0', (string) $pid])->failed())->toBeTrue();
});

it('stays quiet on the cpu while the model is still generating', function (): void {
    fakeClaudeScenario([
        ['do' => 'init'],
        ['do' => 'pause', 'seconds' => 1.5],
        textMessage('m1', 'Slow answer.'),
        ['do' => 'result'],
    ]);
    $process = startFakeClaude('ListCompaniesTool');
    $cpu = function (): float {
        $usage = getrusage();

        return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1_000_000;
    };
    $cpuBefore = $cpu();
    $wallBefore = microtime(true);

    $step = $process->nextStep();

    expect($step->text)->toBe('Slow answer.')
        ->and(microtime(true) - $wallBefore)->toBeGreaterThan(1.4)
        ->and($cpu() - $cpuBefore)->toBeLessThan(0.5);

    $process->release();
});

it('fails the turn when the init line does not show the plan, the rela server and every tool', function (array $init, string $message): void {
    fakeClaudeScenario([['do' => 'init', ...$init], textMessage('m1', 'x'), ['do' => 'result']]);
    $process = startFakeClaude('ListCompaniesTool');

    expect(fn () => $process->nextStep())->toThrow(RuntimeException::class, $message);

    $process->release();
})->with([
    'an api key source' => [['apiKeySource' => 'ANTHROPIC_API_KEY'], 'not signed in with the plan'],
    'no key source field' => [['apiKeySource' => null], 'not signed in with the plan'],
    'a server that failed' => [['mcp_servers' => [['name' => 'rela', 'status' => 'failed']]], 'did not connect the rela tool server'],
    'a missing tool' => [['tools' => []], 'does not offer 1 of the rela tools'],
]);

it('removes its directory and socket when claude cannot be started', function (): void {
    isolatedTempRoot();

    expect(fn () => ClaudeCodeProcess::start('claude-sonnet-5', 'high', 'x', 'y', [['name' => 'T', 'description' => 'T', 'inputSchema' => ['bad' => NAN]]]))->toThrow(JsonException::class)
        ->and(glob(ClaudeCodeProcess::$tempRoot.'/*'))->toBe([]);
});

it('streams a gateway step in the anthropic event order and releases the process after the last step', function (): void {
    fakeClaudeScenario([['do' => 'init'], textMessage('m1', 'Hello there.'), ['do' => 'result']]);
    ClaudeCodeProvider::register();
    $provider = resolve(AiManager::class)->textProvider('claude-code');
    $gateway = new ClaudeCodeGateway;

    $stream = $gateway->generateStreamStep('inv-1', $provider, 'claude-sonnet-5', 'You are Rela.', [new UserMessage('hello')], [], null, null, null, new StepContext);
    $events = [];

    foreach ($stream as $event) {
        $events[] = $event::class;
    }

    $records = ClaudeCodeGateway::takeRecords();

    expect($events)->toBe([StreamStart::class, TextStart::class, TextDelta::class, TextEnd::class])
        ->and($stream->getReturn()->text)->toBe('Hello there.')
        ->and($records)->toHaveCount(1)
        ->and($records[0]['result']['total_cost_usd'])->toBe(0.01)
        ->and(glob(ClaudeCodeProcess::$tempRoot.'/*'))->toBe([]);
});

it('refuses a plan run of a model the claude code gateway cannot serve', function (): void {
    EvalModels::register(null, 'plan');

    expect(fn () => EvalModels::assertPlanServes('gpt-6-luna'))->toThrow(RuntimeException::class, 'PEST_EVALS_RUNNER=plan runs Claude models only, and gpt-6-luna is served by openai')
        ->and(fn () => EvalModels::assertPlanServes('claude-haiku-5-5'))->not->toThrow(RuntimeException::class);
});

it('fails a claude code message that carries no model id instead of storing an empty one', function (): void {
    $line = '{"type":"assistant","message":{"id":"m1","content":[{"type":"text","text":"x"}],"usage":{"input_tokens":1,"output_tokens":1}}}';

    expect(fn () => StreamJson::step($line))->toThrow(RuntimeException::class, 'no model id');
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
