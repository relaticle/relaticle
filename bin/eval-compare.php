<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/tests/Evals/Support/Compare.php';

use Tests\Evals\Support\Compare;

$arguments = array_slice($argv, 1);
$noise = in_array('--noise', $arguments, true);
$split = null;
$directories = [];

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--split=')) {
        $split = substr($argument, 8);
    } elseif ($argument !== '--noise') {
        $directories[] = $argument;
    }
}

if (count($directories) !== ($noise ? 3 : 2)) {
    fwrite(STDERR, "usage: php bin/eval-compare.php <baseline-run-dir> <candidate-run-dir> [--split=test]\n       php bin/eval-compare.php --noise <run-dir> <run-dir> <run-dir>\n");
    exit(1);
}

try {
    $runs = array_map(Compare::readRun(...), $directories);
    echo $noise ? Compare::noiseReport($runs) : Compare::report($runs[0], $runs[1], $split);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
