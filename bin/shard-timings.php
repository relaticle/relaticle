<?php

declare(strict_types=1);

if ($argc !== 2 || ! is_dir($argv[1])) {
    fwrite(STDERR, "usage: php bin/shard-timings.php <junit directory>\n");
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

    foreach ($xml->xpath('//testsuite[@file][testcase]') ?: [] as $suite) {
        $timings[(string) $suite->testcase[0]['class']] = round((float) $suite['time'], 4);
    }
}

if ($timings === []) {
    fwrite(STDERR, "no test classes found under {$argv[1]}\n");
    exit(1);
}

ksort($timings);

file_put_contents(dirname(__DIR__).'/tests/.pest/shards.json', json_encode([
    'timings' => $timings,
    'checksum' => md5(implode("\n", array_keys($timings))),
    'updated_at' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

echo 'Recorded timings for '.count($timings)." test classes.\n";
