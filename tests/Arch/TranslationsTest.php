<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

pest()->extend(TestCase::class);

/** @return array<string, list<string>> */
function translationsKeptEnglish(): array
{
    return ['packages/Cloud/lang' => ['admin']];
}

/** @return list<string> */
function translationRoots(): array
{
    $base = dirname(__DIR__, 2);
    $patterns = ['lang', 'packages/*/lang', 'packages/*/resources/lang'];
    $roots = [];

    foreach ($patterns as $pattern) {
        foreach (glob($base.'/'.$pattern, GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_dir($directory.'/en') || is_file($directory.'/en.json')) {
                $roots[] = str_replace($base.'/', '', $directory);
            }
        }
    }

    $vendorNamespaces = array_map(basename(...), glob($base.'/lang/vendor/*', GLOB_ONLYDIR) ?: []);

    foreach (glob($base.'/vendor/filament/*/resources/lang', GLOB_ONLYDIR) ?: [] as $directory) {
        $vendorNamespaces[] = filamentNamespace(basename(dirname($directory, 2)));
    }

    foreach (array_unique($vendorNamespaces) as $namespace) {
        $roots[] = "lang/vendor/{$namespace}";
    }

    return $roots;
}

function filamentNamespace(string $package): string
{
    return match ($package) {
        'support' => 'filament',
        'filament' => 'filament-panels',
        default => "filament-{$package}",
    };
}

/** @return list<string> */
function translatedLocales(): array
{
    $config = require dirname(__DIR__, 2).'/config/app.php';

    return array_values(array_diff($config['available_locales'], ['en']));
}

/** @return array<string, array<string, mixed>> */
function vendorLangFiles(string $namespace, string $overrideRoot, string $locale): array
{
    $packagePath = app('translator')->getLoader()->namespaces()[$namespace] ?? null;
    $files = [];

    foreach (array_filter([$packagePath, $overrideRoot]) as $root) {
        foreach (glob("{$root}/{$locale}/*.php") ?: [] as $file) {
            $name = basename($file, '.php');
            $files[$name] = array_replace_recursive($files[$name] ?? [], require $file);
        }
    }

    return $files;
}

/** @return string a lang path holding the package's own strings merged with the app's override for en and the locale */
function mergedVendorLangPath(string $namespace, string $overrideRoot, string $locale): string
{
    $path = sys_get_temp_dir().'/'.uniqid("vendor-lang-{$namespace}-", true);

    foreach (['en', $locale] as $code) {
        mkdir("{$path}/{$code}", 0777, true);

        foreach (vendorLangFiles($namespace, $overrideRoot, $code) as $name => $strings) {
            file_put_contents("{$path}/{$code}/{$name}.php", '<?php return '.var_export($strings, true).';');
        }
    }

    return $path;
}

/** @return list<string> the dotted keys the app's own override defines for a locale, so a package's own stray key is not ours to fix */
function overrideKeys(string $overrideRoot, string $locale): array
{
    $keys = [];

    foreach (glob("{$overrideRoot}/{$locale}/*.php") ?: [] as $file) {
        $flatten = function (array $strings, string $prefix) use (&$flatten, &$keys): void {
            foreach ($strings as $key => $value) {
                is_array($value) ? $flatten($value, "{$prefix}.{$key}") : $keys[] = "{$prefix}.{$key}";
            }
        };

        $flatten(require $file, basename($file, '.php'));
    }

    return $keys;
}

/** @return array<string, array<string, string>> */
function packageJsonCatalogues(string $locale): array
{
    $base = dirname(__DIR__, 2);
    $catalogues = [];

    foreach (translationRoots() as $root) {
        if ($root !== 'lang' && is_file("{$base}/{$root}/{$locale}.json")) {
            $catalogues[$root] = json_decode((string) file_get_contents("{$base}/{$root}/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);
        }
    }

    return $catalogues;
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
    $isVendor = str_starts_with($root, 'lang/vendor/');

    if ($isVendor) {
        expect(app('translator')->getLoader()->namespaces())->toHaveKey(basename($root));
        $path = mergedVendorLangPath(basename($root), $path, $locale);
    }

    expect(is_dir("{$path}/{$locale}") || is_file("{$path}/{$locale}.json"))
        ->toBeTrue("{$root} has no {$locale} translations");

    Artisan::call('locale:diff', ['locale' => $locale, '--lang-path' => $path, '--format' => 'json']);
    $diff = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    if ($isVendor) {
        (new Filesystem)->deleteDirectory($path);
    }

    $orphaned = array_map(fn (array $entry): string => $entry['key'], $diff['orphaned']);

    if ($isVendor) {
        $orphaned = array_values(array_intersect($orphaned, overrideKeys(dirname(__DIR__, 2).'/'.$root, $locale)));
    }

    expect($orphaned)->toBe([], "{$root} holds {$locale} keys with no English source");
    $keptEnglish = translationsKeptEnglish()[$root] ?? [];

    $missing = array_values(array_filter(
        array_map(fn (array $entry): string => $entry['key'], $diff['missing']),
        fn (string $key): bool => ! in_array(strstr($key, '.', true), $keptEnglish, true),
    ));

    expect($missing)->toBe([]);
})->with(fn (): array => localeRootRows());

it('gives a key both catalogues define the same value, because the app catalogue wins', function (string $locale): void {
    $base = dirname(__DIR__, 2);
    $app = json_decode((string) file_get_contents("{$base}/lang/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);
    $drifted = [];

    foreach (packageJsonCatalogues($locale) as $root => $package) {
        foreach (array_intersect_key($package, $app) as $key => $value) {
            if ($value !== $app[$key]) {
                $drifted[] = "{$root}: {$key}";
            }
        }
    }

    expect($drifted)->toBe([]);
})->with(fn (): array => array_map(fn (string $locale): array => [$locale], translatedLocales()));
