<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tests\Evals\Support\EvalReport;
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
        toolResultRowIds: [],
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
