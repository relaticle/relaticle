<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Pest\Evals\Contracts\JudgeDriver;
use Pest\Evals\Eval\Evaluation;
use Pest\Evals\Eval\Verdict;
use Tests\Evals\Support\EvalReport;
use Tests\Evals\Support\Prices;
use Tests\Evals\Support\RecordingJudge;
use Tests\Evals\Support\TurnEnvelope;

beforeEach(function (): void {
    EvalReport::reset();
    EvalReport::$directory = sys_get_temp_dir().'/eval-report-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    File::deleteDirectory((string) EvalReport::$directory);
    EvalReport::$directory = null;
    EvalReport::reset();
});

function reportEnvelope(int $in, int $out, int $bytes, int $ms, int $calls, string $text = 'ok', array $steps = []): TurnEnvelope
{
    return new TurnEnvelope(
        text: $text,
        toolCalls: array_fill(0, $calls, ['name' => 'ListTasksTool', 'arguments' => []]),
        renderedRowIds: [],
        replayedBytes: $bytes,
        inputTokens: $in,
        outputTokens: $out,
        durationMs: $ms,
        error: null,
        stepsText: $steps,
    );
}

it('writes nothing when no case was recorded', function (): void {
    expect(EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5'))->toBeNull()
        ->and((string) EvalReport::$directory)->not->toBeDirectory();
});

it('writes one file per run with per-case metrics and totals', function (): void {
    EvalReport::record('first case', true, reportEnvelope(1000, 50, 400, 1200, 2, 'All done.', ['Checking', 'Reading the list']), [], 3);
    EvalReport::record('second case', false, reportEnvelope(3000, 70, 800, 900, 1), ['The reply says contact']);

    $path = EvalReport::flush('gpt-5.5', 'claude-opus-5-5');
    $report = json_decode((string) file_get_contents((string) $path), true, flags: JSON_THROW_ON_ERROR);

    expect($path)->toEndWith('-gpt-5.5.json')
        ->and($path)->toStartWith((string) EvalReport::$directory)
        ->and($report['model'])->toBe('gpt-5.5')
        ->and($report['judge'])->toBe('claude-opus-5-5')
        ->and($report['started_at'])->toBeString()
        ->and($report['cases'][0])->toBe([
            'name' => 'first case',
            'passed' => true,
            'samples' => 3,
            'tokens_in' => 1000,
            'tokens_out' => 50,
            'replayed_bytes' => 400,
            'tool_calls' => 2,
            'duration_ms' => 1200,
            'reply' => 'All done.',
            'steps_text' => [8, 16],
            'reasons' => [],
        ])
        ->and($report['cases'][1]['passed'])->toBeFalse()
        ->and($report['cases'][1]['reasons'])->toBe(['The reply says contact'])
        ->and($report['totals'])->toEqual([
            'cases' => 2,
            'passed' => 1,
            'pass_rate' => 0.5,
            'tokens_in_mean' => 2000.0,
            'replayed_bytes_mean' => 600.0,
            'cost_usd' => null,
            'unpriced_lines' => 2,
            'duration_ms_p50' => 900,
            'duration_ms_p90' => 1200,
            'retries' => 0,
            'pass_rate_by_split' => ['none' => 0.5],
        ]);
});

it('records a case with no envelope as zero metrics and starts the next report empty', function (): void {
    EvalReport::record('no turn', false, null, ['boom']);

    $path = EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5');
    $report = json_decode((string) file_get_contents((string) $path), true, flags: JSON_THROW_ON_ERROR);

    expect($report['cases'][0]['tokens_in'])->toBe(0)
        ->and(EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5'))->toBeNull();
});

it('caps the stored reply at 2000 characters', function (): void {
    EvalReport::record('long reply', true, reportEnvelope(1, 1, 1, 1, 0, str_repeat('a', 2500)));

    $path = EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5');
    $report = json_decode((string) file_get_contents((string) $path), true, flags: JSON_THROW_ON_ERROR);

    expect(mb_strlen($report['cases'][0]['reply']))->toBe(2000);
});

it('counts a case that ran no turn as failed with the reason', function (): void {
    EvalReport::record('errored case', false, null, ['no turn ran: boom']);

    $path = EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5');
    $report = json_decode((string) file_get_contents((string) $path), true, flags: JSON_THROW_ON_ERROR);

    expect($report['cases'][0]['passed'])->toBeFalse()
        ->and($report['cases'][0]['reasons'])->toBe(['no turn ran: boom'])
        ->and($report['totals']['cases'])->toBe(1);
});

it('summarises the run in one line with the report path', function (): void {
    EvalReport::record('first case', true, reportEnvelope(1000, 50, 400, 1200, 2));
    EvalReport::record('second case', false, reportEnvelope(3000, 70, 800, 900, 1));

    $path = EvalReport::flush('gpt-5.5', 'claude-opus-5-5');

    expect(EvalReport::$summary)->toBe("1/2 passed, tokens_in mean 2000, replayed bytes mean 600, report: {$path}");
});

function reportResultLines(): array
{
    $files = glob(EvalReport::$directory.'/*/results.jsonl');

    return array_map(
        fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
    );
}

function cachedEnvelope(): TurnEnvelope
{
    return new TurnEnvelope(
        text: 'ok',
        toolCalls: [],
        renderedRowIds: [],
        replayedBytes: 0,
        inputTokens: 71694,
        outputTokens: 153,
        durationMs: 800,
        error: null,
        servedModel: 'claude-sonnet-5',
        cacheReadTokens: 68958,
        cacheWriteTokens: 2732,
        steps: 2,
    );
}

it('writes one result line per repetition as soon as it is recorded', function (): void {
    EvalReport::record('cached case', true, cachedEnvelope());
    EvalReport::record('cached case', false, cachedEnvelope(), ['wrong reply']);

    $lines = reportResultLines();

    expect($lines)->toHaveCount(2)
        ->and(array_column($lines, 'rep'))->toBe([1, 2])
        ->and($lines[0]['usage']['cache_read'])->toBe(68958)
        ->and($lines[0]['model_served'])->toBe('claude-sonnet-5')
        ->and($lines[1]['passed'])->toBeFalse()
        ->and($lines[1]['reasons'])->toBe(['wrong reply']);
});

it('prices a line from the served model and leaves an unknown model unpriced', function (): void {
    expect(Prices::cost('claude-sonnet-5', input: 1_000_000, cacheRead: 0, cacheWrite: 0, output: 0))->toBe(2.0)
        ->and(Prices::cost('mystery-model', input: 1_000_000, cacheRead: 0, cacheWrite: 0, output: 0))->toBeNull();
});

it('prices only the uncached remainder of the prompt', function (): void {
    EvalReport::record('cached case', true, cachedEnvelope());

    expect(reportResultLines()[0]['cost_usd'])->toBe(0.02216);
});

it('writes a trace per repetition holding every turn', function (): void {
    EvalReport::record('Two turn case', true, cachedEnvelope(), turns: [cachedEnvelope(), reportEnvelope(1, 1, 1, 1, 0)]);

    $trace = json_decode((string) file_get_contents(glob(EvalReport::$directory.'/*/traces/*/two-turn-case-*_rep1.json')[0]), true, flags: JSON_THROW_ON_ERROR);

    expect($trace)->toHaveCount(2)
        ->and($trace[0]['text'])->toBe('ok');
});

it('keeps the run summary at its current path and adds cost and split totals', function (): void {
    EvalReport::record('first case', true, cachedEnvelope());
    EvalReport::record('second case', false, reportEnvelope(10, 1, 1, 1000, 0));

    $path = EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5');
    $report = json_decode((string) file_get_contents((string) $path), true, flags: JSON_THROW_ON_ERROR);

    expect($path)->toMatch('#/\d{4}-\d{2}-\d{2}-\d{6}-claude-sonnet-5\.json$#')
        ->and($report['totals'])->toHaveKeys(['cost_usd', 'unpriced_lines', 'duration_ms_p50', 'duration_ms_p90', 'retries', 'pass_rate_by_split'])
        ->and($report['totals']['cost_usd'])->toBe(0.02216)
        ->and($report['totals']['unpriced_lines'])->toBe(1)
        ->and($report['totals']['pass_rate_by_split']['none'])->toBe(0.5)
        ->and($report['run_dir'])->toBeDirectory();
});

it('prices a failed zero-token turn at zero and counts unknown-model lines as unpriced', function (): void {
    EvalReport::record('priced', true, cachedEnvelope());
    EvalReport::record('failed', false, TurnEnvelope::failed('provider down'));

    $report = json_decode((string) file_get_contents((string) EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5')), true, flags: JSON_THROW_ON_ERROR);

    expect($report['totals']['cost_usd'])->toBe(0.02216)
        ->and($report['totals']['unpriced_lines'])->toBe(0);

    EvalReport::record('priced', true, cachedEnvelope());
    EvalReport::record('unknown', true, reportEnvelope(10, 1, 1, 1, 0));

    $report = json_decode((string) file_get_contents((string) EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5')), true, flags: JSON_THROW_ON_ERROR);

    expect($report['totals']['cost_usd'])->toBe(0.02216)
        ->and($report['totals']['unpriced_lines'])->toBe(1);
});

it('keeps the rep counter and trace per file when two files record the same case name', function (): void {
    EvalReport::record('same name', true, cachedEnvelope(), file: 'ReadsTest');
    EvalReport::record('same name', true, cachedEnvelope(), file: 'WritesTest');

    expect(array_column(reportResultLines(), 'rep'))->toBe([1, 1])
        ->and(glob(EvalReport::$directory.'/*/traces/*/same-name-*_rep1.json'))->toHaveCount(2);
});

it('keeps two case names that share a slug apart', function (): void {
    EvalReport::record('Does a thing', true, cachedEnvelope(), file: 'ReadsTest');
    EvalReport::record('does a thing!', true, cachedEnvelope(), file: 'ReadsTest');

    expect(glob(EvalReport::$directory.'/*/traces/*/does-a-thing-*_rep1.json'))->toHaveCount(2);
});

it('takes duration percentiles from healthy turns only', function (): void {
    EvalReport::record('first', true, null, turns: [reportEnvelope(1, 1, 1, 100, 0), reportEnvelope(1, 1, 1, 300, 0), TurnEnvelope::failed('x')]);
    EvalReport::record('second', true, null, turns: [TurnEnvelope::failed('y')]);

    $report = json_decode((string) file_get_contents((string) EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5')), true, flags: JSON_THROW_ON_ERROR);

    expect($report['totals']['duration_ms_p50'])->toBe(100)
        ->and($report['totals']['duration_ms_p90'])->toBe(300);

    EvalReport::record('only failed', false, null, turns: [TurnEnvelope::failed('z')]);
    $report = json_decode((string) file_get_contents((string) EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5')), true, flags: JSON_THROW_ON_ERROR);

    expect($report['totals']['duration_ms_p50'])->toBeNull()
        ->and($report['totals']['duration_ms_p90'])->toBeNull();
});

it('keeps the served model out of the scored json and in the trace', function (): void {
    $envelope = cachedEnvelope();

    expect(json_decode($envelope->toJson(), true))->not->toHaveKeys(['served_model', 'steps', 'retries'])
        ->and(json_decode($envelope->toJson(), true)['usage'])->not->toHaveKey('cache_read')
        ->and($envelope->toTrace()['served_model'])->toBe('claude-sonnet-5')
        ->and($envelope->toTrace()['usage']['cache_read'])->toBe(68958);
});

it('records a judged case and clears the flag when the wrapper is reset', function (): void {
    RecordingJudge::$used = false;
    $inner = new class implements JudgeDriver
    {
        public function judge(Evaluation $evaluation): Verdict
        {
            return new Verdict(1.0, 'fine');
        }
    };

    $verdict = (new RecordingJudge($inner))->judge(new Evaluation(['a' => 'b'], 'q', ['bad' => 0.0, 'good' => 1.0]));

    expect($verdict->score)->toBe(1.0)
        ->and(RecordingJudge::$used)->toBeTrue();

    RecordingJudge::$used = false;
});

it('reports the path it cannot write', function (): void {
    $blocker = (string) EvalReport::$directory;
    file_put_contents($blocker, 'a file where the directory should be');

    expect(fn () => EvalReport::record('blocked', true, cachedEnvelope()))->toThrow(RuntimeException::class, $blocker);

    unlink($blocker);
});
