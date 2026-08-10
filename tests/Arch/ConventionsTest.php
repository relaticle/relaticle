<?php

declare(strict_types=1);

/**
 * Guards documented conventions that pest-arch and PHPStan cannot express.
 * Each check enforces a rule stated in .ai/guidelines/relaticle/.
 */

/** @return list<string> */
function migrationFiles(): array
{
    $root = dirname(__DIR__, 2);

    $directories = [
        $root.'/database/migrations',
        ...glob($root.'/packages/*/database/migrations', GLOB_ONLYDIR) ?: [],
    ];

    $files = [];

    foreach ($directories as $directory) {
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $files[] = $file;
        }
    }

    return $files;
}

it('keeps migrations forward-only (no down methods)', function (): void {
    $offenders = array_values(array_filter(
        migrationFiles(),
        fn (string $file): bool => str_contains((string) file_get_contents($file), 'function down('),
    ));

    expect($offenders)->toBe(
        [],
        'Migrations are forward-only (.ai/guidelines/relaticle/core.md) — remove down() from: '.implode(', ', $offenders),
    );
});

it('keeps compiled agent guidelines in sync with their .ai sources', function (): void {
    $root = dirname(__DIR__, 2);

    $sources = glob($root.'/.ai/guidelines/relaticle/*.md') ?: [];

    expect($sources)->not->toBe([]);

    foreach (['CLAUDE.md', 'AGENTS.md', 'GEMINI.md'] as $compiled) {
        $compiledContent = (string) file_get_contents($root.'/'.$compiled);

        foreach ($sources as $source) {
            $relativeSource = str_replace($root.'/', '', $source);

            expect(str_contains($compiledContent, trim((string) file_get_contents($source))))->toBeTrue(
                "{$compiled} is stale — it no longer contains the current content of {$relativeSource}. ".
                'Run `php artisan boost:update`, then copy AGENTS.md to GEMINI.md (boost does not write it).',
            );
        }
    }
});

it('keeps every action on the canonical single-execute() shape', function (): void {
    $root = dirname(__DIR__, 2);

    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Actions')),
        '/\.php$/',
    );

    $violations = [];

    foreach ($files as $file) {
        $path = (string) $file;

        // Fortify/Jetstream action shapes are dictated by their framework contracts.
        if (str_contains($path, '/Actions/Fortify/') || str_contains($path, '/Actions/Jetstream/')) {
            continue;
        }

        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], mb_substr($path, mb_strlen($root.'/app/')));

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isInterface() || $reflection->isAbstract()) {
            continue;
        }

        $publicMethods = array_values(array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class,
            ),
        ));

        $unexpected = array_diff($publicMethods, ['__construct', 'execute']);

        if ($unexpected !== [] || ! in_array('execute', $publicMethods, true)) {
            $violations[$class] = implode(', ', $publicMethods);
        }
    }

    expect($violations)->toBe(
        [],
        'Actions expose exactly one public method, execute() (.ai/guidelines/relaticle/architecture.md) — fix: '.json_encode($violations),
    );
});

it('forces a conscious arch-coverage decision when a package is added', function (): void {
    $root = dirname(__DIR__, 2);

    /** @var array{autoload: array{"psr-4": array<string, string>}} $composer */
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    $packageNamespaces = [];

    foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
        if (str_starts_with($path, 'packages/')) {
            $packageNamespaces[] = rtrim($namespace, '\\');
        }
    }

    sort($packageNamespaces);

    expect($packageNamespaces)->toBe(
        [
            'Relaticle\Chat',
            'Relaticle\Documentation',
            'Relaticle\EmailIntegration',
            'Relaticle\ImportWizard',
            'Relaticle\OnboardSeed',
            'Relaticle\SystemAdmin',
        ],
        'Package list changed. Wire the new namespace into tests/Arch/ArchTest.php (boundary + structure rules), '.
        'the package table in .ai/guidelines/relaticle/architecture.md, and then update this list.',
    );
});
