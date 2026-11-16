<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use InvalidArgumentException;
use RuntimeException;

final class Compare
{
    private const float Z_95 = 1.96;

    /**
     * @param  list<float>  $baseline
     * @param  list<float>  $candidate
     * @return array{diff: float, low: float, high: float}
     */
    public static function pairedInterval(array $baseline, array $candidate): array
    {
        $count = count($baseline);

        if ($count === 0 || $count !== count($candidate)) {
            throw new InvalidArgumentException('A paired interval needs the same, non-zero number of cases on both sides.');
        }

        $diffs = array_map(fn (float $before, float $after): float => $after - $before, $baseline, array_values($candidate));
        $diff = array_sum($diffs) / $count;

        if ($count === 1) {
            return ['diff' => $diff, 'low' => $diff, 'high' => $diff];
        }

        $variance = array_sum(array_map(fn (float $value): float => ($value - $diff) ** 2, $diffs)) / ($count - 1);

        if ($variance === 0.0) {
            return ['diff' => $diff, 'low' => $diff, 'high' => $diff];
        }

        $margin = self::Z_95 * sqrt($variance / $count);

        return ['diff' => $diff, 'low' => $diff - $margin, 'high' => $diff + $margin];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function readRun(string $directory): array
    {
        $path = rtrim($directory, '/').'/results.jsonl';

        if (! is_dir($directory)) {
            throw new RuntimeException("The run directory {$directory} does not exist.");
        }

        if (! is_file($path)) {
            throw new RuntimeException("The run directory {$directory} holds no results.jsonl.");
        }

        $lines = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $index => $raw) {
            if (trim($raw) === '') {
                continue;
            }

            $line = json_decode($raw, true);

            if (! is_array($line) || ! is_string($line['case'] ?? null) || ! is_bool($line['passed'] ?? null)) {
                throw new RuntimeException("{$path} line ".($index + 1).' is not a result line.');
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $baseline
     * @param  list<array<string, mixed>>  $candidate
     */
    public static function report(array $baseline, array $candidate, ?string $split = null): string
    {
        $base = self::cases(self::inSplit($baseline, $split));
        $cand = self::cases(self::inSplit($candidate, $split));
        $both = array_values(array_intersect(array_keys($base), array_keys($cand)));
        $out = ['Split: '.($split ?? 'all'), 'Cases: baseline '.count($base).', candidate '.count($cand).', in both '.count($both)];

        $out[] = sprintf('Pass rate: baseline %s, candidate %s', self::percent(self::passRate($base)), self::percent(self::passRate($cand)));

        if ($both !== []) {
            $interval = self::pairedInterval(
                array_map(fn (string $key): float => self::share($base[$key]), $both),
                array_map(fn (string $key): float => self::share($cand[$key]), $both),
            );
            $out[] = sprintf('Paired difference: %s (95%% interval %s to %s)', self::signed($interval['diff']), self::signed($interval['low']), self::signed($interval['high']));
        }

        array_push($out, ...self::flipTable($base, $cand, $both));
        array_push($out, ...self::missing('baseline', array_diff_key($cand, $base)), ...self::missing('candidate', array_diff_key($base, $cand)));

        foreach (['baseline' => $baseline, 'candidate' => $candidate] as $label => $lines) {
            $scoped = self::inSplit($lines, $split);
            $out[] = '';
            $out[] = ucfirst($label).':';
            array_push($out, ...self::runFigures($scoped));
            $out[] = '  guard pass rate: '.self::guardRate($lines);
        }

        return implode("\n", $out)."\n";
    }

    /**
     * @param  list<list<array<string, mixed>>>  $runs
     */
    public static function noiseReport(array $runs): string
    {
        $cases = array_map(fn (array $lines): array => self::cases($lines), $runs);
        $shared = array_keys(array_intersect_key(...$cases));
        $differing = array_filter($shared, function (string $key) use ($cases): bool {
            $shares = array_map(fn (array $run): float => round(self::share($run[$key]), 9), $cases);

            return max($shares) !== min($shares);
        });
        $rates = array_map(fn (array $run): float => self::passRate($run), $cases);
        $out = [
            'Runs: '.count($runs).', cases in every run: '.count($shared),
            sprintf('Cases whose pass share differs between runs: %d of %d (%s)', count($differing), count($shared), self::percent($shared === [] ? 0.0 : count($differing) / count($shared))),
            'Pass rate per run: '.implode(', ', array_map(self::percent(...), $rates)),
            'Largest pass-rate gap between runs: '.self::percent(max($rates) - min($rates)),
        ];

        foreach ($cases as $index => $run) {
            array_push($out, ...self::missing('run '.($index + 1), array_diff_key(array_replace(...$cases), $run)));
        }

        return implode("\n", $out)."\n";
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private static function inSplit(array $lines, ?string $split): array
    {
        return $split === null ? $lines : array_values(array_filter($lines, fn (array $line): bool => ($line['split'] ?? null) === $split));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, array{name: string, passes: int, reps: int}>
     */
    private static function cases(array $lines): array
    {
        $cases = [];

        foreach ($lines as $line) {
            $key = (string) ($line['file'] ?? '').'|'.(string) $line['case'];
            $cases[$key] ??= ['name' => (string) $line['case'], 'passes' => 0, 'reps' => 0];
            $cases[$key]['reps']++;
            $cases[$key]['passes'] += $line['passed'] === true ? 1 : 0;
        }

        return $cases;
    }

    /**
     * @param  array{name: string, passes: int, reps: int}  $case
     */
    private static function share(array $case): float
    {
        return $case['passes'] / $case['reps'];
    }

    /**
     * @param  array<string, array{name: string, passes: int, reps: int}>  $cases
     */
    private static function passRate(array $cases): float
    {
        return $cases === [] ? 0.0 : array_sum(array_map(self::share(...), $cases)) / count($cases);
    }

    /**
     * @param  array<string, array{name: string, passes: int, reps: int}>  $base
     * @param  array<string, array{name: string, passes: int, reps: int}>  $cand
     * @param  list<string>  $both
     * @return list<string>
     */
    private static function flipTable(array $base, array $cand, array $both): array
    {
        $rows = [];

        foreach ($both as $key) {
            if (self::share($base[$key]) !== self::share($cand[$key])) {
                $rows[] = sprintf('  %s | %d/%d | %d/%d', $base[$key]['name'], $base[$key]['passes'], $base[$key]['reps'], $cand[$key]['passes'], $cand[$key]['reps']);
            }
        }

        return [
            '',
            'Flips (case | baseline passes | candidate passes), '.count($rows).' of '.count($both).' cases:',
            ...($rows === [] ? ['  none'] : $rows),
        ];
    }

    /**
     * @param  array<string, array{name: string, passes: int, reps: int}>  $absent
     * @return list<string>
     */
    private static function missing(string $run, array $absent): array
    {
        if ($absent === []) {
            return [];
        }

        return ['', 'Missing from '.$run.' ('.count($absent).'), left out of the paired difference:', ...array_map(fn (array $case): string => '  '.$case['name'], array_values($absent))];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<string>
     */
    private static function runFigures(array $lines): array
    {
        $costs = array_values(array_filter(array_map(fn (array $line): mixed => $line['cost_usd'] ?? null, $lines), is_numeric(...)));
        $durations = array_values(array_filter(array_map(fn (array $line): mixed => $line['duration_ms'] ?? null, $lines), is_int(...)));
        sort($durations);

        return [
            '  repetitions: '.count($lines),
            '  cost per case: '.($costs === [] ? 'n/a' : sprintf('$%.4f', array_sum($costs) / count($costs))).' (mean over '.count($costs).' priced lines, '.(count($lines) - count($costs)).' lacked a cost)',
            '  duration p50 / p90: '.self::duration($durations, 50).' / '.self::duration($durations, 90),
            '  retries: '.array_sum(array_map(fn (array $line): int => (int) ($line['retries'] ?? 0), $lines)),
        ];
    }

    /**
     * @param  list<int>  $sorted
     */
    private static function duration(array $sorted, int $percent): string
    {
        return $sorted === [] ? 'n/a' : $sorted[(int) max(0, ceil(count($sorted) * $percent / 100) - 1)].' ms';
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private static function guardRate(array $lines): string
    {
        $guard = self::inSplit($lines, 'guard');

        if ($guard === []) {
            return 'n/a (no guard lines)';
        }

        $passed = count(array_filter($guard, fn (array $line): bool => $line['passed'] === true));

        return sprintf('%s (%d of %d lines)', self::percent($passed / count($guard)), $passed, count($guard));
    }

    private static function percent(float $share): string
    {
        return sprintf('%.1f%%', $share * 100);
    }

    private static function signed(float $share): string
    {
        return sprintf('%+.1f pts', $share * 100);
    }
}
