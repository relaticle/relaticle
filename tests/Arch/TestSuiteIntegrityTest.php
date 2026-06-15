<?php

declare(strict_types=1);

/**
 * Guards the test-suite architecture itself.
 *
 * In March 2026 the Unit suite was removed from phpunit.xml while tests/Unit
 * stayed on disk — 141 tests silently stopped running for three months. These
 * checks make that class of failure (and phpunit.xml / phpunit.ci.xml drift)
 * impossible to reintroduce.
 */

/** @return array<string, string> suite name => directory */
function declaredSuites(string $configPath): array
{
    $xml = simplexml_load_file($configPath);

    if ($xml === false) {
        throw new RuntimeException("Cannot parse {$configPath}");
    }

    $suites = [];

    foreach ($xml->testsuites->testsuite as $suite) {
        $suites[(string) $suite['name']] = (string) $suite->directory;
    }

    return $suites;
}

/** @return list<string> */
function declaredEnvKeys(string $configPath): array
{
    $xml = simplexml_load_file($configPath);

    if ($xml === false) {
        throw new RuntimeException("Cannot parse {$configPath}");
    }

    $keys = [];

    foreach ($xml->php->env as $env) {
        $keys[] = (string) $env['name'];
    }

    return $keys;
}

it('keeps every test file inside a declared phpunit testsuite directory', function (): void {
    $suiteDirectories = array_values(declaredSuites(dirname(__DIR__, 2).'/phpunit.xml'));

    $testFiles = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/tests')),
        '/Test\.php$/',
    );

    $orphans = [];

    foreach ($testFiles as $file) {
        $relative = str_replace(dirname(__DIR__, 2).'/', '', (string) $file);

        $covered = array_any(
            $suiteDirectories,
            fn (string $directory): bool => str_starts_with($relative, $directory.'/'),
        );

        if (! $covered) {
            $orphans[] = $relative;
        }
    }

    expect($orphans)->toBe(
        [],
        'Test files outside every declared testsuite never run. Move them into a suite directory (tests/Feature, tests/Smoke, …) or declare a suite in BOTH phpunit.xml and phpunit.ci.xml: '.implode(', ', $orphans),
    );
});

it('keeps phpunit.ci.xml testsuites identical to phpunit.xml', function (): void {
    expect(declaredSuites(dirname(__DIR__, 2).'/phpunit.ci.xml'))
        ->toBe(declaredSuites(dirname(__DIR__, 2).'/phpunit.xml'));
});

it('keeps every phpunit.xml env key present in phpunit.ci.xml', function (): void {
    $missing = array_values(array_diff(
        declaredEnvKeys(dirname(__DIR__, 2).'/phpunit.xml'),
        declaredEnvKeys(dirname(__DIR__, 2).'/phpunit.ci.xml'),
    ));

    expect($missing)->toBe(
        [],
        'phpunit.ci.xml is the CI overlay of phpunit.xml — it may override values and add CI-only keys, but must never lack a local key: '.implode(', ', $missing),
    );
});
