<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type Line array<string, mixed>
 * @phpstan-type Run array{lines: list<Line>, interrupted_line: int|null}
 * @phpstan-type Cases array<string, array{passes: int, reps: int, judged: bool}>
 */
final class Compare
{
    private const float Z_95 = 1.96;

    private const float NO_SPREAD = 1e-12;

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
        $variance = $count === 1 ? 0.0 : array_sum(array_map(fn (float $value): float => ($value - $diff) ** 2, $diffs)) / ($count - 1);

        if ($variance < self::NO_SPREAD) {
            return ['diff' => $diff, 'low' => $diff, 'high' => $diff];
        }

        $margin = self::Z_95 * sqrt($variance / $count);

        return ['diff' => $diff, 'low' => $diff - $margin, 'high' => $diff + $margin];
    }

    /**
     * @return Run
     */
    public static function readRun(string $directory): array
    {
        $path = rtrim($directory, '/').'/results.jsonl';

        if (! is_dir($directory)) {
            throw new RuntimeException("The run directory {$directory} does not exist.");
        }

        $contents = is_file($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("The run directory {$directory} holds no readable results.jsonl.");
        }

        $rows = explode("\n", $contents);
        $unterminated = (string) array_pop($rows);
        $lines = [];

        foreach ($rows as $index => $raw) {
            if (trim($raw) !== '') {
                $lines[] = self::decode($raw) ?? throw new RuntimeException("{$path} line ".($index + 1).' is not a result line.');
            }
        }

        $interrupted = null;
        $tail = trim($unterminated) === '' ? null : self::decode($unterminated);

        if ($tail !== null) {
            $lines[] = $tail;
        } elseif (trim($unterminated) !== '') {
            $interrupted = count($rows) + 1;
        }

        if ($lines === []) {
            throw new RuntimeException("{$path} holds no result lines.");
        }

        return ['lines' => $lines, 'interrupted_line' => $interrupted];
    }

    /**
     * @param  Run  $baseline
     * @param  Run  $candidate
     */
    public static function report(array $baseline, array $candidate, ?string $split = null, bool $deterministic = false): string
    {
        $runner = self::runnerOf($baseline);

        if ($runner !== self::runnerOf($candidate)) {
            throw new RuntimeException('Refusing to compare a '.$runner.' run with a '.self::runnerOf($candidate).' run: their usage, cost and message history are measured differently.');
        }

        $base = self::cases(self::headline(self::scope($baseline['lines'], $split, $deterministic), $runner));
        $cand = self::cases(self::headline(self::scope($candidate['lines'], $split, $deterministic), $runner));
        $both = array_values(array_intersect(array_keys($base), array_keys($cand)));
        $out = [
            'Split: '.($split ?? 'all').($deterministic ? ', deterministic cases only' : ''),
            'Cases: baseline '.count($base).', candidate '.count($cand).', shared '.count($both),
            sprintf('Pass rate over all cases (not paired): baseline %s, candidate %s', self::percent(self::passRate($base)), self::percent(self::passRate($cand))),
        ];

        array_push($out, ...self::pairedLines($base, $cand, $both));
        array_push($out, ...self::historyLines($runner, ['baseline' => $baseline, 'candidate' => $candidate], $split, $deterministic));
        array_push($out, ...self::clockLines($runner, ['baseline' => $baseline, 'candidate' => $candidate], $split, $deterministic));
        array_push($out, ...self::flipTable($base, $cand, $both));
        array_push($out, ...self::missing('baseline', array_diff_key($cand, $base)), ...self::missing('candidate', array_diff_key($base, $cand)));

        foreach (['baseline' => $baseline, 'candidate' => $candidate] as $label => $run) {
            array_push($out, '', ucfirst($label).':', ...self::runFigures($run, $split, $deterministic));
        }

        return implode("\n", $out)."\n";
    }

    /**
     * @param  list<Run>  $runs
     */
    public static function noiseReport(array $runs, bool $deterministic = false): string
    {
        $runners = array_values(array_unique(array_map(self::runnerOf(...), $runs)));

        if (count($runners) > 1) {
            throw new RuntimeException('Refusing to measure noise over runs from different runners: '.implode(', ', $runners).'.');
        }

        $runner = $runners[0];
        $cases = array_map(fn (array $run): array => self::cases(self::headline(self::scope($run['lines'], null, $deterministic), $runner)), $runs);
        $shared = array_keys(array_intersect_key(...$cases));
        $differing = array_filter($shared, function (string $key) use ($cases): bool {
            $shares = array_map(fn (array $run): float => round(self::share($run[$key]), 9), $cases);

            return max($shares) !== min($shares);
        });
        $rates = array_map(fn (array $run): float => self::passRate(array_intersect_key($run, array_flip($shared))), $cases);
        $out = [
            'Runs: '.count($runs).($deterministic ? ', deterministic cases only' : '').', cases in every run: '.count($shared),
            sprintf('Cases whose pass share differs between runs: %d of %d (%s)', count($differing), count($shared), self::percent($shared === [] ? 0.0 : count($differing) / count($shared))),
            'Pass rate per run, over the cases in every run: '.implode(', ', array_map(self::percent(...), $rates)),
            'Largest pass-rate gap between runs: '.self::percent(max($rates) - min($rates)),
            ...self::historyRates($runner, $runs, $deterministic),
            ...self::clockRates($runner, $runs, $deterministic),
        ];

        foreach (['model_requested' => 'none', 'effort' => 'none'] as $field => $fallback) {
            $values = array_map(fn (array $run): string => self::distinct($run['lines'], $field, $fallback), $runs);

            if (count(array_unique($values)) > 1) {
                $out[] = "Warning: the runs differ in {$field}: ".implode(' | ', $values).'. This is not one configuration.';
            }
        }

        foreach ($runs as $index => $run) {
            if ($run['interrupted_line'] !== null) {
                $out[] = 'Run '.($index + 1).': interrupted, line '.$run['interrupted_line'].' was skipped.';
            }

            array_push($out, ...self::missing('run '.($index + 1), array_diff_key(array_replace(...$cases), $cases[$index])));
        }

        return implode("\n", $out)."\n";
    }

    /**
     * @param  Run  $run
     */
    private static function runnerOf(array $run): string
    {
        $runners = array_values(array_unique(array_map(fn (array $line): string => (string) ($line['runner'] ?? 'api'), $run['lines'])));

        return count($runners) === 1 ? $runners[0] : throw new RuntimeException('A run mixes runners: '.implode(', ', $runners).'.');
    }

    /**
     * @param  list<Line>  $lines
     * @return list<Line>
     */
    private static function headline(array $lines, string $runner): array
    {
        return $runner === 'plan' ? array_values(array_filter($lines, fn (array $line): bool => ($line['history'] ?? false) !== true && ($line['clock'] ?? false) !== true)) : $lines;
    }

    /**
     * @param  list<Line>  $lines
     * @return list<Line>
     */
    private static function historyOnly(array $lines): array
    {
        return array_values(array_filter($lines, fn (array $line): bool => ($line['history'] ?? false) === true));
    }

    /**
     * @param  list<Line>  $lines
     * @return list<Line>
     */
    private static function clockOnly(array $lines): array
    {
        return array_values(array_filter($lines, fn (array $line): bool => ($line['clock'] ?? false) === true));
    }

    /**
     * @param  array<string, Run>  $runs
     * @return list<string>
     */
    private static function historyLines(string $runner, array $runs, ?string $split, bool $deterministic): array
    {
        if ($runner !== 'plan') {
            return [];
        }

        $parts = [];

        foreach ($runs as $label => $run) {
            $history = self::cases(self::historyOnly(self::scope($run['lines'], $split, $deterministic)));
            $parts[] = sprintf('%s %s over %d', $label, $history === [] ? 'n/a' : self::percent(self::passRate($history)), count($history));
        }

        return ['History cases (plan runner replays earlier turns as text, so they are left out of the headline and the paired difference): '.implode(', ', $parts)];
    }

    /**
     * @param  array<string, Run>  $runs
     * @return list<string>
     */
    private static function clockLines(string $runner, array $runs, ?string $split, bool $deterministic): array
    {
        if ($runner !== 'plan') {
            return [];
        }

        $parts = [];

        foreach ($runs as $label => $run) {
            $clock = self::cases(self::clockOnly(self::scope($run['lines'], $split, $deterministic)));
            $parts[] = sprintf('%s %s over %d', $label, $clock === [] ? 'n/a' : self::percent(self::passRate($clock)), count($clock));
        }

        return ['Clock cases (Claude Code injects its own date and timing, so they are left out of the headline and the paired difference): '.implode(', ', $parts)];
    }

    /**
     * @param  list<Run>  $runs
     * @return list<string>
     */
    private static function clockRates(string $runner, array $runs, bool $deterministic): array
    {
        if ($runner !== 'plan') {
            return [];
        }

        $rates = array_map(function (array $run) use ($deterministic): string {
            $clock = self::cases(self::clockOnly(self::scope($run['lines'], null, $deterministic)));

            return $clock === [] ? 'n/a' : self::percent(self::passRate($clock));
        }, $runs);

        return ['Clock cases are left out above. Their pass rate per run: '.implode(', ', $rates)];
    }

    /**
     * @param  list<Run>  $runs
     * @return list<string>
     */
    private static function historyRates(string $runner, array $runs, bool $deterministic): array
    {
        if ($runner !== 'plan') {
            return [];
        }

        $rates = array_map(function (array $run) use ($deterministic): string {
            $history = self::cases(self::historyOnly(self::scope($run['lines'], null, $deterministic)));

            return $history === [] ? 'n/a' : self::percent(self::passRate($history));
        }, $runs);

        return ['History cases are left out above. Their pass rate per run: '.implode(', ', $rates)];
    }

    /**
     * @return Line|null
     */
    private static function decode(string $raw): ?array
    {
        $line = json_decode($raw, true);

        return is_array($line) && is_string($line['case'] ?? null) && is_bool($line['passed'] ?? null) ? $line : null;
    }

    /**
     * @param  list<Line>  $lines
     * @return list<Line>
     */
    private static function scope(array $lines, ?string $split, bool $deterministic): array
    {
        return array_values(array_filter($lines, fn (array $line): bool => ($split === null || ($line['split'] ?? null) === $split)
            && (! $deterministic || ($line['judged'] ?? false) !== true)));
    }

    /**
     * @param  list<Line>  $lines
     * @return Cases
     */
    private static function cases(array $lines): array
    {
        $cases = [];

        foreach ($lines as $line) {
            $key = (string) ($line['file'] ?? '').'|'.(string) $line['case'];
            $cases[$key] ??= ['passes' => 0, 'reps' => 0, 'judged' => false];
            $cases[$key]['reps']++;
            $cases[$key]['passes'] += $line['passed'] === true ? 1 : 0;
            $cases[$key]['judged'] = $cases[$key]['judged'] || ($line['judged'] ?? false) === true;
        }

        return $cases;
    }

    /**
     * @param  array{passes: int, reps: int, judged: bool}  $case
     */
    private static function share(array $case): float
    {
        return $case['passes'] / $case['reps'];
    }

    /**
     * @param  Cases  $cases
     */
    private static function passRate(array $cases): float
    {
        return $cases === [] ? 0.0 : array_sum(array_map(self::share(...), $cases)) / count($cases);
    }

    /**
     * @param  Cases  $base
     * @param  Cases  $cand
     * @param  list<string>  $both
     * @return list<string>
     */
    private static function pairedLines(array $base, array $cand, array $both): array
    {
        if ($both === []) {
            return ['No shared cases, so there is nothing to pair.'];
        }

        $baseShared = array_intersect_key($base, array_flip($both));
        $candShared = array_intersect_key($cand, array_flip($both));
        $interval = self::pairedInterval(
            array_map(fn (string $key): float => self::share($base[$key]), $both),
            array_map(fn (string $key): float => self::share($cand[$key]), $both),
        );
        $reason = match (true) {
            count($both) === 1 => 'one shared case',
            $interval['low'] === 0.0 && $interval['high'] === 0.0 => 'no case moved',
            $interval['low'] === $interval['high'] => 'every shared case moved by the same amount, so there is no spread to measure',
            default => null,
        };

        return [
            sprintf('Pass rate over shared cases: baseline %s, candidate %s', self::percent(self::passRate($baseShared)), self::percent(self::passRate($candShared))),
            'Paired difference: '.self::signed($interval['diff']),
            $reason === null
                ? sprintf('95%% interval: %s to %s', self::signed(max(-1.0, $interval['low'])), self::signed(min(1.0, $interval['high'])))
                : "95% interval: n/a ({$reason})",
        ];
    }

    /**
     * @param  Cases  $base
     * @param  Cases  $cand
     * @param  list<string>  $both
     * @return list<string>
     */
    private static function flipTable(array $base, array $cand, array $both): array
    {
        $rows = [];
        $judged = 0;

        foreach ($both as $key) {
            if (self::share($base[$key]) === self::share($cand[$key])) {
                continue;
            }

            $judged += $base[$key]['judged'] || $cand[$key]['judged'] ? 1 : 0;
            $rows[] = sprintf('  %s | %d/%d | %d/%d', $key, $base[$key]['passes'], $base[$key]['reps'], $cand[$key]['passes'], $cand[$key]['reps']);
        }

        return [
            '',
            'Flips (case | baseline passes | candidate passes): '.count($rows).' of '.count($both).' shared cases, '.$judged.' involve judged cases',
            ...($rows === [] ? ['  none'] : $rows),
        ];
    }

    /**
     * @param  Cases  $absent
     * @return list<string>
     */
    private static function missing(string $run, array $absent): array
    {
        if ($absent === []) {
            return [];
        }

        return ['', 'Missing from '.$run.' ('.count($absent).'), left out of the paired difference:', ...array_map(fn (string $key): string => '  '.$key, array_keys($absent))];
    }

    /**
     * @param  Run  $run
     * @return list<string>
     */
    private static function runFigures(array $run, ?string $split, bool $deterministic): array
    {
        $lines = self::scope($run['lines'], $split, $deterministic);
        $healthy = array_values(array_filter($lines, fn (array $line): bool => ! self::isErrored($line)));
        $spending = array_values(array_filter($lines, fn (array $line): bool => ! self::isErrored($line) || (float) ($line['cost_usd'] ?? 0) > 0));
        $priced = array_values(array_filter(array_map(fn (array $line): mixed => $line['cost_usd'] ?? null, $spending), is_numeric(...)));
        $durations = array_values(array_filter(array_map(fn (array $line): mixed => $line['duration_ms'] ?? null, $healthy), is_int(...)));
        sort($durations);
        $guard = self::cases(self::scope($run['lines'], 'guard', $deterministic));

        return [
            ...($run['interrupted_line'] === null ? [] : ['  interrupted: line '.$run['interrupted_line'].' was skipped']),
            '  repetitions: '.count($lines),
            '  excluded from cost: '.(count($lines) - count($spending)).' errored with no spend; from duration: '.(count($lines) - count($healthy)).' errored or no-turn',
            '  cost per repetition: '.($priced === [] ? 'n/a' : sprintf('$%.4f', array_sum($priced) / count($priced))).' (mean over '.count($priced).' priced, '.(count($spending) - count($priced)).' unpriced)',
            '  duration p50 / p90: '.self::duration($durations, 50).' / '.self::duration($durations, 90).' (over '.count($durations).' repetitions)',
            ...(self::runnerOf($run) === 'plan' ? ['  notional plan cost per repetition: '.self::notional($spending)] : []),
            '  retries: '.array_sum(array_map(fn (array $line): int => (int) ($line['retries'] ?? 0), $lines)),
            '  guard pass rate: '.($guard === [] ? 'n/a (no guard cases)' : self::percent(self::passRate($guard)).' over '.count($guard).' cases'),
        ];
    }

    /**
     * @param  list<Line>  $lines
     */
    private static function notional(array $lines): string
    {
        $costs = array_values(array_filter(array_map(fn (array $line): mixed => $line['notional_cost_usd'] ?? null, $lines), is_numeric(...)));

        return $costs === [] ? 'n/a' : sprintf('$%.4f (mean over %d, %d without a result line)', array_sum($costs) / count($costs), count($costs), count($lines) - count($costs));
    }

    /**
     * @param  Line  $line
     */
    private static function isErrored(array $line): bool
    {
        if (array_key_exists('errored', $line)) {
            return $line['errored'] === true;
        }

        $usage = is_array($line['usage'] ?? null) ? array_sum(array_filter($line['usage'], is_int(...))) : 0;

        return $usage === 0 && ($line['duration_ms'] ?? 0) === 0;
    }

    /**
     * @param  list<Line>  $lines
     */
    private static function distinct(array $lines, string $field, string $fallback): string
    {
        $values = array_unique(array_map(fn (array $line): string => (string) ($line[$field] ?? $fallback), $lines));
        sort($values);

        return implode(',', $values);
    }

    /**
     * @param  list<int>  $sorted
     */
    private static function duration(array $sorted, int $percent): string
    {
        return $sorted === [] ? 'n/a' : $sorted[(int) max(0, ceil(count($sorted) * $percent / 100) - 1)].' ms';
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
