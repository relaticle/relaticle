<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

/** @return array<string, list<string>> */
function translationsKeptEnglish(): array
{
    return ['packages/Cloud/lang' => ['admin']];
}

/** @return list<string> */
function translationRoots(): array
{
    $base = dirname(__DIR__, 2);
    $patterns = ['lang', 'packages/*/lang', 'packages/*/resources/lang', 'lang/vendor/*'];
    $roots = [];

    foreach ($patterns as $pattern) {
        foreach (glob($base.'/'.$pattern, GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_dir($directory.'/en') || is_file($directory.'/en.json')) {
                $roots[] = str_replace($base.'/', '', $directory);
            }
        }
    }

    return $roots;
}

/** @return list<string> */
function translatedLocales(): array
{
    $config = require dirname(__DIR__, 2).'/config/app.php';

    return array_values(array_diff($config['available_locales'], ['en']));
}

/** @return array<string, array{string, string}> */
function localeRootRows(): array
{
    $rows = [];

    foreach (translatedLocales() as $locale) {
        foreach (translationRoots() as $root) {
            $rows["{$locale} {$root}"] = [$locale, $root];
        }
    }

    return $rows;
}

it('keeps the English catalogue in step with the source', function (): void {
    $this->artisan('lang:extract', ['--check' => true])->assertExitCode(0);
});

it('translates every English key of a root into a locale', function (string $locale, string $root): void {
    $path = dirname(__DIR__, 2).'/'.$root;

    expect(is_dir("{$path}/{$locale}") || is_file("{$path}/{$locale}.json"))
        ->toBeTrue("{$root} has no {$locale} translations");

    Artisan::call('locale:diff', ['locale' => $locale, '--lang-path' => $path, '--format' => 'json']);
    $diff = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $keptEnglish = translationsKeptEnglish()[$root] ?? [];

    $missing = array_values(array_filter(
        array_map(fn (array $entry): string => $entry['key'], $diff['missing']),
        fn (string $key): bool => ! in_array(strstr($key, '.', true), $keptEnglish, true),
    ));

    expect($missing)->toBe([]);
})->with(fn (): array => localeRootRows());
