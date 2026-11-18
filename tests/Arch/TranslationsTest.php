<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

/** @return list<string> */
function translationRoots(): array
{
    $base = dirname(__DIR__, 2);
    $patterns = ['lang', 'packages/*/lang', 'packages/*/resources/lang', 'lang/vendor/*'];
    $roots = [];

    foreach ($patterns as $pattern) {
        foreach (glob($base.'/'.$pattern, GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_dir($directory.'/en') || is_file($directory.'/en.json')) {
                $roots[] = $directory;
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

it('keeps the English catalogue in step with the source', function (): void {
    $this->artisan('lang:extract', ['--check' => true])->assertExitCode(0);
});

it('translates every English key into :dataset', function (string $locale): void {
    $missing = [];

    foreach (translationRoots() as $root) {
        $name = str_replace(dirname(__DIR__, 2).'/', '', $root);

        if (! is_dir("{$root}/{$locale}") && ! is_file("{$root}/{$locale}.json")) {
            $missing[] = "{$name}: no {$locale} translations";

            continue;
        }

        Artisan::call('locale:diff', ['locale' => $locale, '--lang-path' => $root, '--format' => 'json']);
        $diff = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        foreach ($diff['missing'] as $entry) {
            $missing[] = "{$name}: {$entry['key']}";
        }
    }

    expect($missing)->toBe([]);
})->with(fn (): array => translatedLocales());
