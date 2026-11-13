<?php

declare(strict_types=1);

$recording = dirname(__DIR__).'/tests/.pest/shards.json';

if (($argv[1] ?? null) === '--over-budget') {
    /** @var array{timings: array<string, float|int>} $recorded */
    $recorded = json_decode((string) file_get_contents($recording), true, flags: JSON_THROW_ON_ERROR);

    $over = array_filter(
        $recorded['timings'],
        fn (float|int $seconds, string $class): bool => $seconds > (str_contains($class, '\\Browser\\') ? 90 : 130),
        ARRAY_FILTER_USE_BOTH,
    );

    arsort($over);

    foreach ($over as $class => $seconds) {
        echo sprintf("::error::%s ran %ds in one process, past its lane. Split the file (.ai/guidelines/relaticle/testing.md).\n", $class, $seconds);
    }

    exit($over === [] ? 0 : 1);
}

if ($argc !== 2 || ! is_dir($argv[1])) {
    fwrite(STDERR, "usage: php bin/shard-timings.php <junit directory>\n       php bin/shard-timings.php --over-budget\n");
    exit(1);
}

$reports = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1], FilesystemIterator::SKIP_DOTS)),
    '/\.xml$/',
);

$timings = [];

foreach ($reports as $report) {
    $xml = simplexml_load_file((string) $report);

    if ($xml === false) {
        fwrite(STDERR, "{$report} is not valid XML\n");
        exit(1);
    }

    foreach ($xml->xpath('//testsuite[@file][.//testcase]') ?: [] as $suite) {
        $class = (string) $suite->xpath('(.//testcase)[1]')[0]['class'];
        $timings[$class] = round((float) $suite['time'], 4);
    }
}

if ($timings === []) {
    fwrite(STDERR, "no test classes found under {$argv[1]}\n");
    exit(1);
}

ksort($timings);

file_put_contents($recording, json_encode([
    'timings' => $timings,
    'checksum' => md5(implode("\n", array_keys($timings))),
    'updated_at' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

echo 'Recorded timings for '.count($timings)." test classes.\n";
