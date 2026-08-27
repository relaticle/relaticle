<?php

declare(strict_types=1);

use Pest\Rector\Rules\ChainExpectCallsRector;
use Pest\Rector\Rules\EnsureTypeChecksFirstRector;
use Pest\Rector\Rules\SimplifyToLiteralBooleanRector;
use Pest\Rector\Rules\UseToBeEmptyRector;
use Pest\Rector\Rules\UseToBeInRector;
use Pest\Rector\Rules\UseToContainRector;
use Pest\Rector\Rules\UseToMatchRector;
use Pest\Rector\Rules\UseToThrowRector;
use Pest\Rector\Set\PestSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withCache(cacheDirectory: __DIR__.'/.cache/rector-tests')
    ->withPaths([__DIR__.'/tests'])
    ->withSkip([
        ChainExpectCallsRector::class,
        EnsureTypeChecksFirstRector::class,
        SimplifyToLiteralBooleanRector::class,
        UseToBeEmptyRector::class,
        UseToBeInRector::class,
        UseToThrowRector::class,
        UseToContainRector::class => [
            __DIR__.'/tests/Feature/Migrations/UlidMigrationTest.php',
        ],
        UseToMatchRector::class => [
            __DIR__.'/tests/Browser/Chat/TranscriptShapeTest.php',
        ],
    ])
    ->withSets([
        PestSetList::CODING_STYLE,
    ]);
