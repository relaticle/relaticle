<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\ToolCall;
use Pest\Evals\Support\ToolCallParser;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\Chat\Services\ModelRegistry;
use Tests\Evals\Support\EvalModels;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;
use Tests\Evals\Support\Splits;
use Tests\Evals\Support\TurnEnvelope;

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

    preg_match_all('/^ - (\S+?)(?:"dataset .*")?$/m', $output, $matches);

    return array_values(array_unique($matches[1]));
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
