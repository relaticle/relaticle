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
use RectorLaravel\Rector\StaticCall\CarbonSetTestNowToTravelToRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;

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
    ->withRules([
        // Same immutability guard as rector.php, plus the test-clock equivalent:
        // Carbon::setTestNow() steers the mutable class, travelTo() steers the
        // factory the code under test actually reads.
        CarbonToDateFacadeRector::class,
        CarbonSetTestNowToTravelToRector::class,
    ])
    ->withSets([
        PestSetList::CODING_STYLE,
    ]);
