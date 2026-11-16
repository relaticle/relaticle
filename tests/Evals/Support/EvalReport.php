<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Relaticle\Chat\Services\ModelRegistry;
use RuntimeException;
use Tests\Evals\Support\ClaudeCode\ClaudeCodeProvider;

final class EvalReport
{
    public const string JUDGE_MODEL = 'claude-opus-5-5';

    private const int REPLY_LIMIT = 2000;

    public static ?string $directory = null;

    public static ?string $summary = null;

    /** @var list<array{name: string, passed: bool, samples: int, tokens_in: int, tokens_out: int, replayed_bytes: int, tool_calls: int, duration_ms: int, reply: string, steps_text: list<int>, reasons: list<string>}> */
    private static array $cases = [];

    private static ?CarbonImmutable $startedAt = null;

    private static bool $shutdownRegistered = false;

    private static ?string $runDirectory = null;

    /** @var array<string, int> */
    private static array $repetitions = [];

    /** @var list<array{passed: bool, split: ?string, cost_usd: ?float, notional_cost_usd: ?float, plan: bool, turn_durations: list<int>, retries: int}> */
    private static array $lines = [];

    /**
     * @param  list<string>  $reasons
     * @param  list<TurnEnvelope>  $turns
     * @param  list<array{level: string|null, score: float, reasoning: string}>  $verdicts
     */
    public static function record(string $testName, bool $passed, ?TurnEnvelope $envelope, array $reasons = [], int $samples = 1, bool $judged = false, array $turns = [], string $file = '', ?string $split = null, array $verdicts = [], bool $history = false, bool $clock = false, int $skippedTurns = 0): void
    {
        self::$startedAt ??= self::wallClock();

        self::$cases[] = [
            'name' => $testName,
            'passed' => $passed,
            'samples' => $samples,
            'tokens_in' => $envelope instanceof TurnEnvelope ? $envelope->inputTokens : 0,
            'tokens_out' => $envelope instanceof TurnEnvelope ? $envelope->outputTokens : 0,
            'replayed_bytes' => $envelope instanceof TurnEnvelope ? $envelope->replayedBytes : 0,
            'tool_calls' => $envelope instanceof TurnEnvelope ? count($envelope->toolCalls) : 0,
            'duration_ms' => $envelope instanceof TurnEnvelope ? $envelope->durationMs : 0,
            'reply' => $envelope instanceof TurnEnvelope ? mb_substr($envelope->text, 0, self::REPLY_LIMIT) : '',
            'steps_text' => $envelope instanceof TurnEnvelope ? array_map(mb_strlen(...), $envelope->stepsText) : [],
            'reasons' => $reasons,
        ];

        self::writeRepetition($testName, $passed, $envelope, $reasons, $samples, $judged, $turns, $file, $split, $verdicts, $history, $clock, $skippedTurns);
    }

    public static function flush(string $model, string $judge): ?string
    {
        if (self::$cases === []) {
            return null;
        }

        $startedAt = self::$startedAt ?? self::wallClock();
        $directory = self::baseDirectory();
        $path = $directory.'/'.$startedAt->format('Y-m-d-His').'-'.self::slug($model).'.json';

        self::ensureDirectory($directory);

        $totals = self::totals();

        file_put_contents($path, json_encode([
            'model' => $model,
            'judge' => $judge,
            'started_at' => $startedAt->toIso8601String(),
            'cases' => self::$cases,
            'totals' => $totals,
            'run_dir' => self::runDirectory(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        self::$summary = "{$totals['passed']}/{$totals['cases']} passed, tokens_in mean {$totals['tokens_in_mean']}, replayed bytes mean {$totals['replayed_bytes_mean']}, report: {$path}";
        self::reset();

        return $path;
    }

    public static function flushOnShutdown(string $model, string $judge): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function(static function () use ($model, $judge): void {
            if (self::flush($model, $judge) !== null) {
                echo PHP_EOL.self::$summary.PHP_EOL;
            }
        });
    }

    public static function reset(): void
    {
        self::$cases = [];
        self::$startedAt = null;
        self::$runDirectory = null;
        self::$repetitions = [];
        self::$lines = [];
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<TurnEnvelope>  $turns
     * @param  list<array{level: string|null, score: float, reasoning: string}>  $verdicts
     */
    private static function writeRepetition(string $testName, bool $passed, ?TurnEnvelope $envelope, array $reasons, int $samples, bool $judged, array $turns, string $file, ?string $split, array $verdicts, bool $history, bool $clock, int $skippedTurns): void
    {
        $turns = $turns === [] && $envelope instanceof TurnEnvelope ? [$envelope] : $turns;
        $rep = self::$repetitions[$file.'|'.$testName] = (self::$repetitions[$file.'|'.$testName] ?? 0) + 1;
        $runDirectory = self::runDirectory();
        $usage = self::usageOf($turns);
        $cost = self::costOf($turns);
        $durationMs = array_sum(array_map(fn (TurnEnvelope $turn): int => $turn->durationMs, $turns));
        $retries = array_sum(array_map(fn (TurnEnvelope $turn): int => $turn->retries, $turns));
        $served = $turns === [] ? '' : $turns[array_key_last($turns)]->servedModel;
        $effort = self::effort();

        $runner = self::runnerFields($turns);

        self::$lines[] = ['passed' => $passed, 'split' => $split, 'cost_usd' => $cost, 'notional_cost_usd' => $runner['notional_cost_usd'] ?? null, 'plan' => $runner['runner'] === 'plan', 'turn_durations' => self::healthyDurations($turns), 'retries' => $retries];

        $traceDirectory = $runDirectory.'/traces/'.(Str::slug($file) ?: 'no-file');
        $slug = (Str::slug($testName) ?: 'case').'-'.substr(md5($testName), 0, 8);
        self::write("{$traceDirectory}/{$slug}_rep{$rep}.json", json_encode(
            array_map(fn (TurnEnvelope $turn): array => $turn->toTrace(), $turns),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n");

        self::write($runDirectory.'/results.jsonl', json_encode([
            'case' => $testName,
            'file' => $file,
            'split' => $split,
            'rep' => $rep,
            'passed' => $passed,
            'judged' => $judged,
            'history' => $history,
            'clock' => $clock,
            'reasons' => $reasons,
            'model_requested' => RelaTurn::model(),
            'model_served' => $served,
            'effort' => $effort === 'none' ? null : $effort,
            'usage' => $usage,
            'cost_usd' => $cost,
            'duration_ms' => $durationMs,
            'errored' => $turns === [] || array_filter($turns, fn (TurnEnvelope $turn): bool => $turn->error !== null) !== [],
            'steps' => array_sum(array_map(fn (TurnEnvelope $turn): int => $turn->steps, $turns)),
            'tool_calls' => array_sum(array_map(fn (TurnEnvelope $turn): int => count($turn->toolCalls), $turns)),
            'retries' => $retries,
            'turns' => $samples,
            'turns_skipped' => $skippedTurns,
            ...$runner,
            ...($verdicts === [] ? [] : ['judge' => $verdicts]),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", append: true);
    }

    /**
     * @param  list<TurnEnvelope>  $turns
     * @return array<string, mixed>
     */
    private static function runnerFields(array $turns): array
    {
        $runner = $turns === [] ? EvalModels::runner() : $turns[array_key_last($turns)]->runner;
        $fields = [
            'runner' => $runner,
            'parallel_tool_calls' => array_sum(array_map(fn (TurnEnvelope $turn): int => $turn->parallelToolCalls, $turns)),
        ];

        if ($runner !== 'plan') {
            return $fields;
        }

        $costs = array_map(fn (TurnEnvelope $turn): ?float => $turn->notionalCostUsd(), $turns);
        $known = $turns !== [] && ! in_array(null, $costs, true);

        return [
            ...$fields,
            'notional_cost_usd' => $known ? round(array_sum($costs), 6) : null,
            ...($known ? [] : ['notional_cost_missing' => 'no result line arrived from Claude Code for at least one run']),
        ];
    }

    private static function write(string $path, string $contents, bool $append = false): void
    {
        self::ensureDirectory(dirname($path));

        if (@file_put_contents($path, $contents, $append ? FILE_APPEND : 0) === false) {
            throw new RuntimeException("The eval report cannot write {$path}.");
        }
    }

    /**
     * @param  list<TurnEnvelope>  $turns
     * @return list<int>
     */
    private static function healthyDurations(array $turns): array
    {
        return array_values(array_map(
            fn (TurnEnvelope $turn): int => $turn->durationMs,
            array_filter($turns, fn (TurnEnvelope $turn): bool => $turn->error === null),
        ));
    }

    /**
     * @param  list<TurnEnvelope>  $turns
     * @return array{input: int, output: int, cache_read: int, cache_write: int, reasoning: int}
     */
    private static function usageOf(array $turns): array
    {
        $sum = fn (callable $read): int => array_sum(array_map($read, $turns));

        return [
            'input' => $sum(fn (TurnEnvelope $turn): int => $turn->inputTokens),
            'output' => $sum(fn (TurnEnvelope $turn): int => $turn->outputTokens),
            'cache_read' => $sum(fn (TurnEnvelope $turn): int => $turn->cacheReadTokens),
            'cache_write' => $sum(fn (TurnEnvelope $turn): int => $turn->cacheWriteTokens),
            'reasoning' => $sum(fn (TurnEnvelope $turn): int => $turn->reasoningTokens),
        ];
    }

    /**
     * @param  list<TurnEnvelope>  $turns
     */
    private static function costOf(array $turns): ?float
    {
        if (array_filter($turns, fn (TurnEnvelope $turn): bool => $turn->runner === 'plan') !== []) {
            return null;
        }

        $total = 0.0;

        foreach ($turns as $turn) {
            $spent = $turn->inputTokens + $turn->outputTokens + $turn->cacheReadTokens + $turn->cacheWriteTokens + $turn->reasoningTokens;
            $cost = $spent === 0 ? 0.0 : Prices::cost(
                $turn->servedModel,
                max(0, $turn->inputTokens - $turn->cacheReadTokens - $turn->cacheWriteTokens),
                $turn->cacheReadTokens,
                $turn->cacheWriteTokens,
                $turn->outputTokens,
            );

            if ($cost === null) {
                return null;
            }

            $total += $cost;
        }

        return round($total, 6);
    }

    private static function effort(): string
    {
        $descriptor = resolve(ModelRegistry::class)->find(RelaTurn::model());

        return in_array($descriptor?->provider, ['anthropic', ClaudeCodeProvider::NAME], true) ? (string) config('chat.anthropic_effort') : 'none';
    }

    private static function runDirectory(): string
    {
        if (self::$runDirectory !== null) {
            return self::$runDirectory;
        }

        $name = implode('-', [
            (self::$startedAt ?? self::wallClock())->format('Y-m-d-His'),
            self::slug(RelaTurn::model()),
            self::slug(self::effort()),
            self::slug((string) (getenv('PEST_EVALS_LABEL') ?: 'run')),
        ]);

        self::ensureDirectory(self::baseDirectory());

        return self::$runDirectory = self::baseDirectory().'/'.$name;
    }

    private static function baseDirectory(): string
    {
        return self::$directory ?? dirname(__DIR__, 3).'/storage/evals';
    }

    private static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("The eval report cannot create {$directory}.");
        }
    }

    private static function slug(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
    }

    /**
     * @return array{cases: int, passed: int, pass_rate: float, tokens_in_mean: float, replayed_bytes_mean: float, cost_usd: ?float, unpriced_lines: int, duration_ms_p50: ?int, duration_ms_p90: ?int, retries: int, pass_rate_by_split: array<string, float>, notional_cost_usd?: float|null, lines_without_notional_cost?: int}
     */
    private static function totals(): array
    {
        $count = count(self::$cases);
        $passed = count(array_filter(self::$cases, fn (array $case): bool => $case['passed']));

        return [
            'cases' => $count,
            'passed' => $passed,
            'pass_rate' => round($passed / $count, 4),
            'tokens_in_mean' => round(array_sum(array_column(self::$cases, 'tokens_in')) / $count, 1),
            'replayed_bytes_mean' => round(array_sum(array_column(self::$cases, 'replayed_bytes')) / $count, 1),
            'cost_usd' => self::totalCost(),
            'unpriced_lines' => count(array_filter(self::$lines, fn (array $line): bool => $line['cost_usd'] === null && ! $line['plan'])),
            'duration_ms_p50' => self::percentile(50),
            'duration_ms_p90' => self::percentile(90),
            'retries' => array_sum(array_column(self::$lines, 'retries')),
            'pass_rate_by_split' => self::passRateBySplit(),
            ...self::planTotals(),
        ];
    }

    /**
     * @return array<string, float|int|null>
     */
    private static function planTotals(): array
    {
        $plan = array_filter(self::$lines, fn (array $line): bool => $line['plan']);

        if ($plan === []) {
            return [];
        }

        $costs = array_filter(array_column($plan, 'notional_cost_usd'), fn (?float $cost): bool => $cost !== null);

        return [
            'notional_cost_usd' => $costs === [] ? null : round(array_sum($costs), 6),
            'lines_without_notional_cost' => count($plan) - count($costs),
        ];
    }

    private static function totalCost(): ?float
    {
        $costs = array_filter(array_column(self::$lines, 'cost_usd'), fn (?float $cost): bool => $cost !== null);

        return $costs === [] ? null : round(array_sum($costs), 6);
    }

    private static function percentile(int $percent): ?int
    {
        $durations = array_merge(...array_column(self::$lines, 'turn_durations'));
        sort($durations);

        return $durations === [] ? null : $durations[(int) max(0, ceil(count($durations) * $percent / 100) - 1)];
    }

    /**
     * @return array<string, float>
     */
    private static function passRateBySplit(): array
    {
        $rates = [];

        foreach (collect(self::$lines)->groupBy(fn (array $line): string => $line['split'] ?? 'none') as $split => $lines) {
            $rates[$split] = round($lines->where('passed', true)->count() / $lines->count(), 4);
        }

        return $rates;
    }

    private static function wallClock(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp(time(), 'UTC');
    }
}
