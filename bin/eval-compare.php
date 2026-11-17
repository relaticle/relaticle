<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/tests/Evals/Support/Compare.php';
require_once dirname(__DIR__).'/tests/Evals/Support/Splits.php';

use Tests\Evals\Support\Compare;
use Tests\Evals\Support\Splits;

$usage = "usage: php bin/eval-compare.php <baseline-run-dir> <candidate-run-dir> [--split=train|test|guard] [--deterministic]\n       php bin/eval-compare.php --noise <run-dir> <run-dir> <run-dir> [--deterministic]\n";
$noise = false;
$deterministic = false;
$split = null;
$directories = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--noise') {
        $noise = true;
    } elseif ($argument === '--deterministic') {
        $deterministic = true;
    } elseif (str_starts_with($argument, '--split=')) {
        $split = substr($argument, 8);
    } elseif (str_starts_with($argument, '--')) {
        fwrite(STDERR, "unknown option {$argument}\n{$usage}");
        exit(1);
    } else {
        $directories[] = $argument;
    }
}

if ($split !== null && ! in_array($split, Splits::NAMES, true)) {
    fwrite(STDERR, '--split takes one of '.implode(', ', Splits::NAMES).".\n");
    exit(1);
}

if ($noise && $split !== null) {
    fwrite(STDERR, "--split does not apply to --noise.\n");
    exit(1);
}

if (count($directories) !== ($noise ? 3 : 2)) {
    fwrite(STDERR, $usage);
    exit(1);
}

try {
    $runs = array_map(Compare::readRun(...), $directories);
    echo $noise ? Compare::noiseReport($runs, $deterministic) : Compare::report($runs[0], $runs[1], $split, $deterministic);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
