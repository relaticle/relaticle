<?php

declare(strict_types=1);

// Splits the TIA baseline recording across CI jobs and merges their graphs into one.
// Pest records a graph only for a run it considers complete, so each job runs a whole
// configuration whose Default suite lists its own share of the test files.

const CONFIG_FILE = 'phpunit.tia.xml';
const GRAPH_FILE = 'graph.json';

$root = dirname(__DIR__);

match ($argv[1] ?? null) {
    'config' => writeShardConfig($root, (int) ($argv[2] ?? 0), (int) ($argv[3] ?? 0)),
    'merge' => mergeGraphs($argv[2] ?? '', $argv[3] ?? ''),
    default => fail("usage: php bin/tia-baseline.php config <shard> <total>\n       php bin/tia-baseline.php merge <parts directory> <output directory>"),
};

function writeShardConfig(string $root, int $shard, int $total): void
{
    if ($total < 1 || $shard < 1 || $shard > $total) {
        fail("shard must be between 1 and the total, got {$shard}/{$total}");
    }

    $config = new DOMDocument;
    $config->preserveWhiteSpace = false;
    $config->formatOutput = true;
    $config->load($root.'/phpunit.xml');

    $suite = (new DOMXPath($config))->query('//testsuite[@name="Default"]')->item(0)
        ?? fail('phpunit.xml has no Default testsuite');

    $files = [];

    foreach ($suite->getElementsByTagName('directory') as $directory) {
        $files = [...$files, ...testFiles($root, $directory->textContent)];
    }

    $shares = shareByTime($files, testTimings($root), $total);

    while ($suite->firstChild !== null) {
        $suite->removeChild($suite->firstChild);
    }

    foreach ($shares[$shard - 1] as $file) {
        $suite->appendChild($config->createElement('file', $file));
    }

    $config->save($root.'/'.CONFIG_FILE);

    echo sprintf("Shard %d/%d: %d of %d test files in %s\n", $shard, $total, count($shares[$shard - 1]), count($files), CONFIG_FILE);
}

/**
 * @return list<string>
 */
function testFiles(string $root, string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (str_ends_with($file->getFilename(), 'Test.php')) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    sort($files);

    return $files;
}

/**
 * @return array<string, float>
 */
function testTimings(string $root): array
{
    $recorded = json_decode((string) @file_get_contents($root.'/tests/.pest/shards.json'), true);

    return is_array($recorded['timings'] ?? null) ? $recorded['timings'] : [];
}

/**
 * @param  list<string>  $files
 * @param  array<string, float>  $timings
 * @return list<list<string>>
 */
function shareByTime(array $files, array $timings, int $total): array
{
    $known = array_filter(array_map(fn (string $file): ?float => $timings[testClass($file)] ?? null, $files));
    $fallback = $known === [] ? 1.0 : array_sum($known) / count($known);

    $weights = [];

    foreach ($files as $file) {
        $weights[$file] = $timings[testClass($file)] ?? $fallback;
    }

    arsort($weights);

    $shares = array_fill(0, $total, []);
    $loads = array_fill(0, $total, 0.0);

    foreach ($weights as $file => $weight) {
        $lightest = array_keys($loads, min($loads))[0];
        $shares[$lightest][] = $file;
        $loads[$lightest] += $weight;
    }

    return array_map(function (array $share): array {
        sort($share);

        return $share;
    }, $shares);
}

function testClass(string $file): string
{
    return ucfirst(str_replace('/', '\\', substr($file, 0, -strlen('.php'))));
}

function mergeGraphs(string $partsDirectory, string $outputDirectory): void
{
    $parts = glob(rtrim($partsDirectory, '/').'/*/'.GRAPH_FILE) ?: [];

    if ($parts === [] || $outputDirectory === '') {
        fail("no {$partsDirectory}/*/".GRAPH_FILE.' to merge');
    }

    $merged = null;
    $fileIds = [];

    foreach ($parts as $part) {
        $graph = json_decode((string) file_get_contents($part), true, flags: JSON_THROW_ON_ERROR);

        if (($graph['schema'] ?? null) !== 1) {
            fail("{$part} has an unknown graph schema; update bin/tia-baseline.php for this Pest version");
        }

        $merged ??= [...$graph, 'files' => [], 'edges' => [], 'baselines' => [], 'test_tables' => []];

        if ($graph['fingerprint'] !== $merged['fingerprint']) {
            fail("{$part} was recorded against a different fingerprint");
        }

        foreach ($graph['edges'] as $test => $ids) {
            $merged['edges'][$test] = array_map(function (int $id) use ($graph, &$merged, &$fileIds): int {
                $file = $graph['files'][$id];

                if (! isset($fileIds[$file])) {
                    $fileIds[$file] = count($merged['files']);
                    $merged['files'][] = $file;
                }

                return $fileIds[$file];
            }, $ids);
        }

        $merged['test_tables'] += $graph['test_tables'];

        foreach ($graph['baselines'] as $branch => $baseline) {
            $current = $merged['baselines'][$branch] ?? [...$baseline, 'results' => []];

            if ($current['sha'] !== $baseline['sha']) {
                fail("{$part} was recorded at {$baseline['sha']}, another part at {$current['sha']}");
            }

            $current['results'] += $baseline['results'];
            $current['tree'] = array_diff_key($current['tree'] + $baseline['tree'], [CONFIG_FILE => true]);
            $merged['baselines'][$branch] = $current;
        }
    }

    if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, recursive: true)) {
        fail("cannot create {$outputDirectory}");
    }

    file_put_contents($outputDirectory.'/'.GRAPH_FILE, json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    echo sprintf("Merged %d parts: %d test files, %d results\n", count($parts), count($merged['edges']), array_sum(array_map(fn (array $baseline): int => count($baseline['results']), $merged['baselines'])));
}

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}
