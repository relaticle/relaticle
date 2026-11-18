<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Pest\Evals\Contracts\JudgeDriver;
use Pest\Evals\Eval\Evaluation;
use Pest\Evals\Eval\Verdict;
use Tests\Evals\Support\Compare;
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

it('names the run by the wall clock even when the first case moved the app clock', function (): void {
    $this->travelTo('2026-08-28 12:00:00');

    EvalReport::record('dated case', true, reportEnvelope(10, 5, 0, 100, 0));

    expect((string) EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5'))->not->toContain('2026-08-28');
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
        ->and(RecordingJudge::$used)->toBeTrue()
        ->and(RecordingJudge::$verdicts)->toBe([['level' => null, 'score' => 1.0, 'reasoning' => 'fine']]);

    EvalReport::record('judged case', true, cachedEnvelope(), judged: true, verdicts: RecordingJudge::$verdicts);

    expect(reportResultLines()[0]['judge'])->toEqual([['level' => null, 'score' => 1.0, 'reasoning' => 'fine']]);

    RecordingJudge::$used = false;
    RecordingJudge::$verdicts = [];
});

it('reports the path it cannot write', function (): void {
    $blocker = (string) EvalReport::$directory;
    file_put_contents($blocker, 'a file where the directory should be');

    expect(fn () => EvalReport::record('blocked', true, cachedEnvelope()))->toThrow(RuntimeException::class, $blocker);

    unlink($blocker);
});

it('reports no difference inside the interval for identical runs', function (): void {
    $r = Compare::pairedInterval([1.0, 0.0, 1.0, 1.0], [1.0, 0.0, 1.0, 1.0]);

    expect($r['diff'])->toBe(0.0)->and($r['low'])->toBe(0.0)->and($r['high'])->toBe(0.0);
});

it('reports a paired gain with its interval', function (): void {
    $r = Compare::pairedInterval([0.0, 0.0, 1.0, 1.0], [1.0, 1.0, 1.0, 1.0]);

    expect($r['diff'])->toBe(0.5)->and($r['low'])->toBeGreaterThan(-0.1)->and($r['high'])->toBeLessThan(1.1)
        ->and($r['low'])->toEqualWithDelta(-0.0658, 0.0005)
        ->and($r['high'])->toEqualWithDelta(1.0658, 0.0005);
});

it('collapses the interval for a single case and rejects empty input', function (): void {
    $r = Compare::pairedInterval([0.0], [1.0]);

    expect($r)->toBe(['diff' => 1.0, 'low' => 1.0, 'high' => 1.0])
        ->and(fn () => Compare::pairedInterval([], []))->toThrow(InvalidArgumentException::class);
});

it('marks a repetition whose turn failed or never ran as errored on its result line', function (): void {
    EvalReport::record('clean', true, cachedEnvelope());
    EvalReport::record('crashed', false, TurnEnvelope::failed('boom'), ['boom']);
    EvalReport::record('no turn', false, null, ['no turn ran']);

    expect(array_column(reportResultLines(), 'errored'))->toBe([false, true, true]);
});

function writeRunFixture(string $name, array $lines, string $suffix = ''): string
{
    $directory = (string) EvalReport::$directory.'/'.$name;
    File::ensureDirectoryExists($directory);
    $defaults = ['file' => 'f.php', 'split' => 'test', 'judged' => false, 'cost_usd' => 0.1, 'duration_ms' => 100, 'retries' => 0, 'errored' => false, 'model_requested' => 'm', 'effort' => 'high'];
    file_put_contents($directory.'/results.jsonl', implode('', array_map(
        fn (array $line): string => json_encode($line + $defaults, JSON_THROW_ON_ERROR)."\n",
        $lines,
    )).$suffix);

    return $directory;
}

function comparisonFixtures(): array
{
    return [
        writeRunFixture('baseline', [
            ['case' => 'steady', 'passed' => true, 'duration_ms' => 100, 'cost_usd' => 0.10],
            ['case' => 'steady', 'passed' => true, 'duration_ms' => 300, 'cost_usd' => 0.30, 'retries' => 1],
            ['case' => 'flipper', 'passed' => false, 'duration_ms' => 200, 'cost_usd' => 0.20, 'judged' => true],
            ['case' => 'flipper', 'passed' => true, 'duration_ms' => 400, 'cost_usd' => 0.40, 'judged' => true],
            ['case' => 'dropped', 'passed' => true, 'duration_ms' => 500, 'cost_usd' => 0.50],
            ['case' => 'crasher', 'passed' => false, 'duration_ms' => 0, 'cost_usd' => 0.0, 'errored' => true],
            ['case' => 'guarded', 'passed' => true, 'split' => 'guard'],
        ]),
        writeRunFixture('candidate', [
            ['case' => 'steady', 'passed' => true],
            ['case' => 'steady', 'passed' => true],
            ['case' => 'flipper', 'passed' => true, 'judged' => true],
            ['case' => 'flipper', 'passed' => true, 'judged' => true],
            ['case' => 'added', 'passed' => false, 'duration_ms' => 900, 'cost_usd' => null, 'retries' => 2],
            ['case' => 'crasher', 'passed' => true],
            ['case' => 'guarded', 'passed' => false, 'split' => 'guard'],
        ]),
    ];
}

it('compares two run directories and pins the headline figures', function (): void {
    [$baseline, $candidate] = comparisonFixtures();

    $result = Process::run([PHP_BINARY, base_path('bin/eval-compare.php'), $baseline, $candidate, '--split=test']);
    $output = $result->output();

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($output)->toContain('Cases: baseline 4, candidate 4, shared 3')
        ->and($output)->toContain('Pass rate over all cases (not paired): baseline 62.5%, candidate 75.0%')
        ->and($output)->toContain('Pass rate over shared cases: baseline 50.0%, candidate 100.0%')
        ->and($output)->toContain('Paired difference: +50.0 pts')
        ->and($output)->toContain('95% interval: -6.6 pts to +100.0 pts')
        ->and($output)->toContain('2 of 3 shared cases, 1 involve judged cases')
        ->and($output)->toContain('f.php|flipper | 1/2 | 2/2')
        ->and($output)->toContain('f.php|crasher | 0/1 | 1/1')
        ->and($output)->not->toContain('f.php|steady |')
        ->and($output)->toMatch('/Missing from candidate \(1\).*\n  f\.php\|dropped/')
        ->and($output)->toMatch('/Missing from baseline \(1\).*\n  f\.php\|added/')
        ->and($output)->toContain('excluded from cost: 1 errored with no spend; from duration: 1 errored or no-turn')
        ->and($output)->toContain('cost per repetition: $0.3000 (mean over 5 priced, 0 unpriced)')
        ->and($output)->toContain('duration p50 / p90: 300 ms / 500 ms (over 5 repetitions)')
        ->and($output)->toContain('retries: 1')
        ->and($output)->toContain('cost per repetition: $0.1000 (mean over 5 priced, 1 unpriced)')
        ->and($output)->toContain('duration p50 / p90: 100 ms / 900 ms (over 6 repetitions)')
        ->and($output)->toContain('retries: 2')
        ->and($output)->toContain('guard pass rate: 100.0% over 1 cases')
        ->and($output)->toContain('guard pass rate: 0.0% over 1 cases');
});

it('drops judged lines on request so the headline rests on deterministic cases', function (): void {
    [$baseline, $candidate] = comparisonFixtures();

    $report = Compare::report(Compare::readRun($baseline), Compare::readRun($candidate), 'test', deterministic: true);

    expect($report)->toContain('deterministic cases only')
        ->and($report)->toContain('Cases: baseline 3, candidate 3, shared 2')
        ->and($report)->toContain('1 of 2 shared cases, 0 involve judged cases')
        ->and($report)->not->toContain('flipper');
});

it('prints no interval when there is nothing to measure the spread from', function (): void {
    $one = Compare::report(
        Compare::readRun(writeRunFixture('one-a', [['case' => 'x', 'passed' => false]])),
        Compare::readRun(writeRunFixture('one-b', [['case' => 'x', 'passed' => true]])),
    );
    $same = Compare::report(
        Compare::readRun(writeRunFixture('same-a', [['case' => 'x', 'passed' => false], ['case' => 'y', 'passed' => false]])),
        Compare::readRun(writeRunFixture('same-b', [['case' => 'x', 'passed' => true], ['case' => 'y', 'passed' => true]])),
    );
    $still = writeRunFixture('still', [['case' => 'x', 'passed' => true], ['case' => 'y', 'passed' => false]]);
    $unchanged = Compare::report(Compare::readRun($still), Compare::readRun($still));

    expect($unchanged)->toContain('95% interval: n/a (no case moved)')
        ->and($one)->toContain('95% interval: n/a (one shared case)')
        ->and($same)->toContain('95% interval: n/a (every shared case moved by the same amount')
        ->and($same)->toContain('Paired difference: +100.0 pts');
});

it('counts the spend of an errored repetition in cost but keeps it out of duration', function (): void {
    $run = Compare::readRun(writeRunFixture('spent', [
        ['case' => 'x', 'passed' => true, 'cost_usd' => 0.1, 'duration_ms' => 100, 'errored' => false],
        ['case' => 'x', 'passed' => false, 'cost_usd' => 0.3, 'duration_ms' => 9000, 'errored' => true],
        ['case' => 'y', 'passed' => false, 'cost_usd' => 0.0, 'duration_ms' => 0, 'errored' => true],
    ]));

    expect(Compare::report($run, $run))->toContain('cost per repetition: $0.2000 (mean over 2 priced, 0 unpriced)')
        ->toContain('duration p50 / p90: 100 ms / 100 ms (over 1 repetitions)')
        ->toContain('excluded from cost: 1 errored with no spend; from duration: 2 errored or no-turn');
});

it('skips an interrupted last line, rejects a bad middle line and an empty run', function (): void {
    $interrupted = writeRunFixture('cut', [['case' => 'x', 'passed' => true]], '{"case":"y","pas');
    $middle = writeRunFixture('bad-middle', [['case' => 'x', 'passed' => true]]);
    file_put_contents($middle.'/results.jsonl', "not json\n".file_get_contents($middle.'/results.jsonl'));
    $empty = writeRunFixture('empty', []);

    expect(Compare::readRun($interrupted))->toMatchArray(['interrupted_line' => 2])
        ->and(Compare::readRun($interrupted)['lines'])->toHaveCount(1)
        ->and(Compare::report(Compare::readRun($interrupted), Compare::readRun($interrupted)))->toContain('interrupted: line 2 was skipped')
        ->and(fn () => Compare::readRun($middle))->toThrow(RuntimeException::class, 'results.jsonl line 1')
        ->and(fn () => Compare::readRun($empty))->toThrow(RuntimeException::class, 'holds no result lines')
        ->and(fn () => Compare::readRun($empty.'/nope'))->toThrow(RuntimeException::class, 'does not exist');
});

it('measures the noise floor on shared cases and warns when the runs differ in configuration', function (): void {
    $runs = array_map(Compare::readRun(...), [
        writeRunFixture('a', [['case' => 'x', 'passed' => true], ['case' => 'y', 'passed' => true], ['case' => 'only-a', 'passed' => false]]),
        writeRunFixture('b', [['case' => 'x', 'passed' => false], ['case' => 'y', 'passed' => true]]),
        writeRunFixture('c', [['case' => 'x', 'passed' => true], ['case' => 'y', 'passed' => true]]),
    ]);
    $other = Compare::readRun(writeRunFixture('d', [['case' => 'x', 'passed' => true], ['case' => 'y', 'passed' => true]]));
    $other['lines'][0]['effort'] = 'low';

    $noise = Compare::noiseReport($runs);

    expect($noise)->toContain('cases in every run: 2')
        ->and($noise)->toContain('differs between runs: 1 of 2')
        ->and($noise)->toContain('Pass rate per run, over the cases in every run: 100.0%, 50.0%, 100.0%')
        ->and($noise)->toContain('Largest pass-rate gap between runs: 50.0%')
        ->and($noise)->toContain('Missing from run 2 (1)')
        ->and($noise)->not->toContain('Warning')
        ->and(Compare::noiseReport([$runs[0], $runs[1], $other]))->toContain('Warning: the runs differ in effort');
});

it('refuses an unknown split, a split with noise and a missing directory', function (): void {
    [$baseline, $candidate] = comparisonFixtures();
    $run = fn (array $arguments): int => Process::run([PHP_BINARY, base_path('bin/eval-compare.php'), ...$arguments])->exitCode();

    expect($run([$baseline, $candidate, '--split=bogus']))->toBe(1)
        ->and($run(['--noise', $baseline, $baseline, $baseline, '--split=test']))->toBe(1)
        ->and($run([$baseline, $candidate.'/nope']))->toBe(1)
        ->and($run([$baseline, $candidate, '--nosie']))->toBe(1)
        ->and($run([$baseline, $candidate]))->toBe(0);
});

function planRun(float $cost = 0.25): array
{
    return ['input' => 'hi', 'result' => [
        'total_cost_usd' => $cost,
        'usage' => ['input_tokens' => 3, 'cache_read_input_tokens' => 30, 'cache_creation_input_tokens' => 15, 'output_tokens' => 11, 'output_tokens_details' => ['thinking_tokens' => 5]],
    ]];
}

it('records the runner and the parallel tool call count on the result line and keeps them out of the scored json', function (): void {
    $envelope = cachedEnvelope()->withRunner('plan', [planRun()]);
    $parallel = new TurnEnvelope('ok', [], [], 0, 10, 5, 100, null, parallelToolCalls: 2);

    EvalReport::record('plan case', true, $envelope, turns: [$parallel->withRunner('plan', [planRun(0.5)]), $envelope]);
    EvalReport::record('api case', true, cachedEnvelope());

    [$plan, $api] = reportResultLines();

    expect($plan['runner'])->toBe('plan')
        ->and($plan['parallel_tool_calls'])->toBe(2)
        ->and($plan['notional_cost_usd'])->toBe(0.75)
        ->and($api['runner'])->toBe('api')
        ->and($api['parallel_tool_calls'])->toBe(0)
        ->and($api)->not->toHaveKey('notional_cost_usd')
        ->and(json_decode($envelope->toJson(), true))->not->toHaveKeys(['runner', 'parallel_tool_calls', 'claude_code'])
        ->and($envelope->toTrace()['runner'])->toBe('plan')
        ->and($envelope->toTrace()['claude_code'][0]['input'])->toBe('hi');
});

it('takes a plan line usage from the result line and leaves its API price null', function (): void {
    EvalReport::record('plan case', true, cachedEnvelope()->withRunner('plan', [planRun()]));

    [$line] = reportResultLines();

    expect($line['cost_usd'])->toBeNull()
        ->and($line['usage'])->toBe(['input' => 48, 'output' => 11, 'cache_read' => 30, 'cache_write' => 15, 'reasoning' => 5])
        ->and($line['notional_cost_usd'])->toBe(0.25)
        ->and($line)->not->toHaveKey('notional_cost_missing');
});

it('writes a null notional cost with the reason when no result line arrived', function (): void {
    EvalReport::record('plan case', false, cachedEnvelope()->withRunner('plan', [['input' => 'hi', 'result' => null]]));
    EvalReport::record('failed plan case', false, TurnEnvelope::failed('boom')->withRunner('plan'));

    [$silent, $failed] = reportResultLines();

    expect($silent['notional_cost_usd'])->toBeNull()
        ->and($silent['notional_cost_missing'])->toContain('no result line')
        ->and($failed['notional_cost_usd'])->toBeNull()
        ->and($failed['cost_usd'])->toBeNull();
});

it('keeps plan notional cost out of the API cost and the unpriced count in the totals', function (): void {
    EvalReport::record('plan case', true, cachedEnvelope()->withRunner('plan', [planRun(0.25)]));
    EvalReport::record('silent plan case', true, cachedEnvelope()->withRunner('plan', [['input' => 'hi', 'result' => null]]));

    $report = json_decode((string) file_get_contents((string) EvalReport::flush('claude-sonnet-5', 'claude-opus-5-5')), true, flags: JSON_THROW_ON_ERROR);

    expect($report['totals']['cost_usd'])->toBeNull()
        ->and($report['totals']['unpriced_lines'])->toBe(0)
        ->and($report['totals']['notional_cost_usd'])->toBe(0.25)
        ->and($report['totals']['lines_without_notional_cost'])->toBe(1);
});

it('records the clock group and the skipped turns on the result line', function (): void {
    EvalReport::record('on the clock', true, cachedEnvelope(), clock: true, skippedTurns: 2);
    EvalReport::record('off the clock', true, cachedEnvelope());

    $lines = reportResultLines();

    expect(array_column($lines, 'clock'))->toBe([true, false])
        ->and(array_column($lines, 'turns_skipped'))->toBe([2, 0]);
});

it('records the history group on the result line', function (): void {
    EvalReport::record('with history', true, cachedEnvelope(), history: true);
    EvalReport::record('without history', true, cachedEnvelope());

    expect(array_column(reportResultLines(), 'history'))->toBe([true, false]);
});

it('keeps the runner and the retry count when an envelope is rebuilt', function (): void {
    $envelope = cachedEnvelope()->withRunner('plan')->withRetries(2);

    expect($envelope->runner)->toBe('plan')
        ->and($envelope->retries)->toBe(2)
        ->and($envelope->servedModel)->toBe('claude-sonnet-5')
        ->and(TurnEnvelope::failed('boom')->runner)->toBe('api');
});

function runnerFixture(string $name, string $runner, array $lines): string
{
    return writeRunFixture($name, array_map(fn (array $line): array => $line + ['runner' => $runner], $lines));
}

it('refuses to compare a plan run with an API run and names both runners', function (): void {
    $plan = runnerFixture('plan', 'plan', [['case' => 'one', 'passed' => true]]);
    $api = runnerFixture('api', 'api', [['case' => 'one', 'passed' => true]]);

    $result = Process::run([PHP_BINARY, base_path('bin/eval-compare.php'), $plan, $api]);

    expect($result->successful())->toBeFalse()
        ->and($result->errorOutput())->toContain('Refusing to compare a plan run with a api run');

    $legacy = writeRunFixture('legacy', [['case' => 'one', 'passed' => true]]);

    expect(Process::run([PHP_BINARY, base_path('bin/eval-compare.php'), $legacy, $api])->successful())->toBeTrue()
        ->and(Process::run([PHP_BINARY, base_path('bin/eval-compare.php'), $legacy, $plan])->successful())->toBeFalse();
});

it('refuses to measure noise over mixed runners', function (): void {
    $lines = [['case' => 'one', 'passed' => true]];
    $directories = [runnerFixture('r1', 'plan', $lines), runnerFixture('r2', 'plan', $lines), runnerFixture('r3', 'api', $lines)];

    $result = Process::run([PHP_BINARY, base_path('bin/eval-compare.php'), '--noise', ...$directories]);

    expect($result->successful())->toBeFalse()
        ->and($result->errorOutput())->toContain('different runners: plan, api');
});

it('reports plan history cases apart from the headline and the paired difference', function (): void {
    $baseline = runnerFixture('plan-base', 'plan', [
        ['case' => 'plain', 'passed' => true],
        ['case' => 'replays', 'passed' => false, 'history' => true],
    ]);
    $candidate = runnerFixture('plan-cand', 'plan', [
        ['case' => 'plain', 'passed' => true],
        ['case' => 'replays', 'passed' => true, 'history' => true],
    ]);

    $output = Process::run([PHP_BINARY, base_path('bin/eval-compare.php'), $baseline, $candidate])->throw()->output();

    expect($output)->toContain('Cases: baseline 1, candidate 1, shared 1')
        ->and($output)->toContain('Paired difference: +0.0 pts')
        ->and($output)->toContain('History cases (plan runner replays earlier turns as text, so they are left out of the headline and the paired difference): baseline 0.0% over 1, candidate 100.0% over 1')
        ->and($output)->not->toContain('replays |');

    $noise = Process::run([PHP_BINARY, base_path('bin/eval-compare.php'), '--noise', $baseline, $candidate, $baseline])->throw()->output();

    expect($noise)->toContain('cases in every run: 1')
        ->and($noise)->toContain('History cases are left out above. Their pass rate per run: 0.0%, 100.0%, 0.0%');
});

it('reports plan clock cases apart from the headline and the paired difference', function (): void {
    $baseline = runnerFixture('clock-base', 'plan', [
        ['case' => 'plain', 'passed' => true],
        ['case' => 'dated', 'passed' => false, 'clock' => true],
    ]);
    $candidate = runnerFixture('clock-cand', 'plan', [
        ['case' => 'plain', 'passed' => true],
        ['case' => 'dated', 'passed' => true, 'clock' => true],
    ]);

    $output = Process::run([PHP_BINARY, base_path('bin/eval-compare.php'), $baseline, $candidate])->throw()->output();

    expect($output)->toContain('Cases: baseline 1, candidate 1, shared 1')
        ->and($output)->toContain('Paired difference: +0.0 pts')
        ->and($output)->toContain('Clock cases (Claude Code injects its own date and timing, so they are left out of the headline and the paired difference): baseline 0.0% over 1, candidate 100.0% over 1')
        ->and($output)->not->toContain('dated |');

    $noise = Process::run([PHP_BINARY, base_path('bin/eval-compare.php'), '--noise', $baseline, $candidate, $baseline])->throw()->output();

    expect($noise)->toContain('cases in every run: 1')
        ->and($noise)->toContain('Clock cases are left out above. Their pass rate per run: 0.0%, 100.0%, 0.0%');
});
