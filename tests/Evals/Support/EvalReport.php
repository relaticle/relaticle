<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use Carbon\CarbonImmutable;

final class EvalReport
{
    public const string JUDGE_MODEL = 'claude-opus-5-5';

    public static ?string $directory = null;

    /** @var list<array{name: string, passed: bool, samples: int, tokens_in: int, tokens_out: int, replayed_bytes: int, tool_calls: int, duration_ms: int, reasons: list<string>}> */
    private static array $cases = [];

    private static ?CarbonImmutable $startedAt = null;

    private static bool $shutdownRegistered = false;

    /**
     * @param  list<string>  $reasons
     */
    public static function record(string $testName, bool $passed, ?TurnEnvelope $envelope, array $reasons = [], int $samples = 1): void
    {
        self::$startedAt ??= now();

        self::$cases[] = [
            'name' => $testName,
            'passed' => $passed,
            'samples' => $samples,
            'tokens_in' => $envelope instanceof TurnEnvelope ? $envelope->inputTokens : 0,
            'tokens_out' => $envelope instanceof TurnEnvelope ? $envelope->outputTokens : 0,
            'replayed_bytes' => $envelope instanceof TurnEnvelope ? $envelope->replayedBytes : 0,
            'tool_calls' => $envelope instanceof TurnEnvelope ? count($envelope->toolCalls) : 0,
            'duration_ms' => $envelope instanceof TurnEnvelope ? $envelope->durationMs : 0,
            'reasons' => $reasons,
        ];
    }

    public static function flush(string $model, string $judge): ?string
    {
        if (self::$cases === []) {
            return null;
        }

        $startedAt = self::$startedAt ?? now();
        $directory = self::$directory ?? dirname(__DIR__, 3).'/storage/evals';
        $path = $directory.'/'.$startedAt->format('Y-m-d-His').'-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $model).'.json';

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, json_encode([
            'model' => $model,
            'judge' => $judge,
            'started_at' => $startedAt->toIso8601String(),
            'cases' => self::$cases,
            'totals' => self::totals(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

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
            $path = self::flush($model, $judge);

            if ($path !== null) {
                echo PHP_EOL."Eval report written to {$path}".PHP_EOL;
            }
        });
    }

    public static function reset(): void
    {
        self::$cases = [];
        self::$startedAt = null;
    }

    /**
     * @return array{cases: int, passed: int, pass_rate: float, tokens_in_mean: float, replayed_bytes_mean: float}
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
        ];
    }
}
