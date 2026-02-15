<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Privatization\Rector\ClassMethod\PrivatizeFinalClassMethodRector;
use RectorLaravel\Rector\Class_\AddHasFactoryToModelsRector;
use RectorLaravel\Rector\Class_\UseForwardsCallsTraitRector;
use RectorLaravel\Rector\Empty_\EmptyToBlankAndFilledFuncRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(laravel: true)
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/app-modules',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/public',
    ])
    ->withSkip([
        AddOverrideAttributeToOverriddenMethodsRector::class,
        RemoveUnusedPrivateMethodRector::class => [
            // Skip Filament importer lifecycle hooks - they're called dynamically via callHook()
            __DIR__.'/app/Filament/Imports/*',
        ],
        PrivatizeFinalClassMethodRector::class => [
            // Filament expects protected visibility for lifecycle hooks
            __DIR__.'/app/Filament/Imports/*',
        ],
        ArrayToFirstClassCallableRector::class => [
            // class_exists has optional bool param that conflicts with Collection::first signature
            __DIR__.'/app/Providers/AppServiceProvider.php',
        ],
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
            // class_exists has optional bool param that conflicts with Collection::first signature
            __DIR__.'/app/Providers/AppServiceProvider.php',
        ],
        AddHasFactoryToModelsRector::class => [
            __DIR__.'/app-modules/ImportWizard/src/Models/*',
        ],
    ])
    ->withSets([
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_FACTORIES,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_TESTING,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
    ])
    ->withRules([
        EmptyToBlankAndFilledFuncRector::class,
        UseForwardsCallsTraitRector::class,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withPhpSets();
