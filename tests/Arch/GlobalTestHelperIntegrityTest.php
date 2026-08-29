<?php

declare(strict_types=1);

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** @var Closure(): list<string> $testPhpFiles */
$testPhpFiles = static function (): array {
    $testsRoot = dirname(__DIR__);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsRoot, FilesystemIterator::SKIP_DOTS),
    );
    $files = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
};

it('requires unique global test helper names', function () use ($testPhpFiles): void {
    $projectRoot = dirname(__DIR__, 2);
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $nodeFinder = new NodeFinder;
    $declarations = [];

    foreach ($testPhpFiles() as $file) {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException("Cannot read {$file}");
        }

        $statements = $parser->parse($source) ?? [];

        foreach ($nodeFinder->findInstanceOf($statements, Function_::class) as $function) {
            $name = strtolower($function->name->toString());
            $relativeFile = str_replace($projectRoot.DIRECTORY_SEPARATOR, '', $file);
            $declarations[$name][] = "{$relativeFile}:{$function->getStartLine()}";
        }
    }

    $collisions = [];

    foreach ($declarations as $name => $locations) {
        if (count($locations) > 1) {
            $collisions[] = "{$name}: ".implode(', ', $locations);
        }
    }

    sort($collisions);

    expect($collisions)->toBe(
        [],
        "Global test helper names must be unique:\n".implode("\n", $collisions),
    );
});

it('forbids function exists guards in tests', function () use ($testPhpFiles): void {
    $projectRoot = dirname(__DIR__, 2);
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $nodeFinder = new NodeFinder;
    $guards = [];

    foreach ($testPhpFiles() as $file) {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException("Cannot read {$file}");
        }

        $statements = $parser->parse($source) ?? [];

        foreach ($nodeFinder->findInstanceOf($statements, FuncCall::class) as $functionCall) {
            if (! $functionCall->name instanceof Name || strtolower($functionCall->name->toString()) !== 'function_exists') {
                continue;
            }

            $relativeFile = str_replace($projectRoot.DIRECTORY_SEPARATOR, '', $file);
            $guards[] = "{$relativeFile}:{$functionCall->getStartLine()}";
        }
    }

    sort($guards);

    expect($guards)->toBe(
        [],
        "Test helper declarations must not use function_exists guards:\n".implode("\n", $guards),
    );
});
