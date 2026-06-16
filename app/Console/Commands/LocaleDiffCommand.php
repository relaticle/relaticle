<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Finder\Finder;

/**
 * Reports translation key drift between `lang/en/` and `lang/<locale>/`.
 *
 * Three diff kinds:
 *   - missing  — key in en, not in locale
 *   - orphaned — key in locale, not in en
 *   - stale    — key in both, but the en value's hash has drifted since
 *                the translator last ran `--update-snapshot`
 *
 * Covers both PHP array files and the top-level JSON file (e.g. `lang/en.json`).
 *
 * Snapshots live at `lang/.snapshots/<locale>.json` and should be committed
 * to the fork so team members share translation state.
 */
#[Description('Compare keys/values between lang/en/ and lang/<locale>/, report missing, orphaned, and stale translations')]
#[Signature('locale:diff
        {locale : Locale code (ISO 15897, e.g. fr, pt_BR)}
        {--lang-path= : Override lang directory root}
        {--format=text : Output format: text or json}
        {--update-snapshot : Write the current en hashes as the new baseline; skip diff output}')]
final class LocaleDiffCommand extends Command
{
    public function handle(): int
    {
        $locale = (string) $this->argument('locale');
        $root = (string) ($this->option('lang-path') ?? lang_path());
        $format = (string) $this->option('format');
        $updateSnapshot = (bool) $this->option('update-snapshot');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error("Invalid --format: {$format}. Allowed: text, json.");

            return self::FAILURE;
        }

        $enPath = $root.DIRECTORY_SEPARATOR.'en';
        $localePath = $root.DIRECTORY_SEPARATOR.$locale;
        $enJsonPath = $root.DIRECTORY_SEPARATOR.'en.json';
        $localeJsonPath = $root.DIRECTORY_SEPARATOR.$locale.'.json';

        if (! is_dir($enPath)) {
            $this->error("Source directory not found: {$enPath}");

            return self::FAILURE;
        }

        $enValues = $this->collectValues($enPath, $enJsonPath);

        if ($updateSnapshot) {
            $this->writeSnapshot($root, $locale, $enValues);

            return self::SUCCESS;
        }

        if (! is_dir($localePath)) {
            $this->error("Target directory not found: {$localePath}");

            return self::FAILURE;
        }

        $localeValues = $this->collectValues($localePath, $localeJsonPath);
        $snapshot = $this->loadSnapshot($root, $locale);

        $diff = $this->computeDiff($enValues, $localeValues, $snapshot);

        if ($format === 'json') {
            $this->line($this->renderJson($locale, $diff));
        } else {
            $this->renderText($locale, $diff);
        }

        return $this->isEmpty($diff) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{php: array<string, string>, json: array<string, string>}
     */
    private function collectValues(string $directory, string $jsonPath): array
    {
        return [
            'php' => $this->collectPhpValues($directory),
            'json' => $this->collectJsonValues($jsonPath),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function collectPhpValues(string $directory): array
    {
        $values = [];

        foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
            $relative = str_replace('\\', '/', mb_substr($file->getRelativePathname(), 0, -4));
            $contents = require $file->getRealPath();

            if (! is_array($contents)) {
                continue;
            }

            foreach (Arr::dot($contents) as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                $values["{$relative}.{$key}"] = $value;
            }
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function collectJsonValues(string $jsonPath): array
    {
        if (! is_file($jsonPath)) {
            return [];
        }

        $contents = file_get_contents($jsonPath);

        if ($contents === false) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $values = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (! is_string($value)) {
                continue;
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param  array{php: array<string, string>, json: array<string, string>}  $en
     * @param  array{php: array<string, string>, json: array<string, string>}  $locale
     * @param  array<string, string>  $snapshot
     * @return array{missing: list<array{key: string, kind: string}>, orphaned: list<array{key: string, kind: string}>, stale: list<array{key: string, kind: string, current_en_value: string}>}
     */
    private function computeDiff(array $en, array $locale, array $snapshot): array
    {
        $missing = [];
        $orphaned = [];
        $stale = [];

        foreach (['php', 'json'] as $kind) {
            $enKeys = array_keys($en[$kind]);
            $localeKeys = array_keys($locale[$kind]);

            foreach (array_diff($enKeys, $localeKeys) as $key) {
                $missing[] = ['key' => $key, 'kind' => $kind];
            }

            foreach (array_diff($localeKeys, $enKeys) as $key) {
                $orphaned[] = ['key' => $key, 'kind' => $kind];
            }

            foreach ($en[$kind] as $key => $value) {
                if (! isset($locale[$kind][$key])) {
                    continue;
                }

                $snapshotKey = $this->snapshotKey($kind, $key);

                if (! isset($snapshot[$snapshotKey])) {
                    continue;
                }

                if ($snapshot[$snapshotKey] !== $this->hashValue($value)) {
                    $stale[] = [
                        'key' => $key,
                        'kind' => $kind,
                        'current_en_value' => $value,
                    ];
                }
            }
        }

        $sortByKey = static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']);
        usort($missing, $sortByKey);
        usort($orphaned, $sortByKey);
        usort($stale, $sortByKey);

        return ['missing' => $missing, 'orphaned' => $orphaned, 'stale' => $stale];
    }

    private function snapshotKey(string $kind, string $key): string
    {
        return $kind === 'json' ? "[json] {$key}" : $key;
    }

    private function hashValue(string $value): string
    {
        // Content-equality hash, not cryptographic. sha256 picked because the
        // pest security preset bans md5/sha1; hash() with an explicit algorithm
        // reads as obviously non-crypto and keeps the arch test green.
        return hash('sha256', $value);
    }

    /**
     * @param  array{php: array<string, string>, json: array<string, string>}  $enValues
     */
    private function writeSnapshot(string $root, string $locale, array $enValues): void
    {
        $hashes = [];

        foreach ($enValues['php'] as $key => $value) {
            $hashes[$this->snapshotKey('php', $key)] = $this->hashValue($value);
        }

        foreach ($enValues['json'] as $key => $value) {
            $hashes[$this->snapshotKey('json', $key)] = $this->hashValue($value);
        }

        ksort($hashes);

        $snapshotPath = $root.DIRECTORY_SEPARATOR.'.snapshots'.DIRECTORY_SEPARATOR.$locale.'.json';

        if (! is_dir(dirname($snapshotPath))) {
            mkdir(dirname($snapshotPath), 0755, recursive: true);
        }

        $payload = [
            'generated_at' => Date::now()->toIso8601String(),
            'en_hashes' => $hashes,
        ];

        file_put_contents(
            $snapshotPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->info("Snapshot written: {$snapshotPath} (".count($hashes).' keys).');
    }

    /**
     * @return array<string, string>
     */
    private function loadSnapshot(string $root, string $locale): array
    {
        $path = $root.DIRECTORY_SEPARATOR.'.snapshots'.DIRECTORY_SEPARATOR.$locale.'.json';

        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($decoded) || ! isset($decoded['en_hashes']) || ! is_array($decoded['en_hashes'])) {
            return [];
        }

        $hashes = [];

        foreach ($decoded['en_hashes'] as $key => $hash) {
            if (! is_string($key)) {
                continue;
            }
            if (! is_string($hash)) {
                continue;
            }
            $hashes[$key] = $hash;
        }

        return $hashes;
    }

    /**
     * @param  array{missing: list<array{key: string, kind: string}>, orphaned: list<array{key: string, kind: string}>, stale: list<array{key: string, kind: string, current_en_value: string}>}  $diff
     */
    private function renderText(string $locale, array $diff): void
    {
        foreach ($diff['missing'] as $entry) {
            $this->line("Missing in {$locale}: ".$this->formatKey($entry));
        }

        foreach ($diff['orphaned'] as $entry) {
            $this->line("Orphaned in {$locale}: ".$this->formatKey($entry));
        }

        foreach ($diff['stale'] as $entry) {
            $this->line("Stale in {$locale}: ".$this->formatKey($entry)." (current en: \"{$entry['current_en_value']}\")");
        }

        if ($this->isEmpty($diff)) {
            $this->info("Locale {$locale} matches en — no missing, orphaned, or stale keys.");
        }
    }

    /**
     * @param  array{key: string, kind: string}  $entry
     */
    private function formatKey(array $entry): string
    {
        return $entry['kind'] === 'json' ? "[json] {$entry['key']}" : $entry['key'];
    }

    /**
     * @param  array{missing: list<array{key: string, kind: string}>, orphaned: list<array{key: string, kind: string}>, stale: list<array{key: string, kind: string, current_en_value: string}>}  $diff
     */
    private function renderJson(string $locale, array $diff): string
    {
        return json_encode(
            ['locale' => $locale] + $diff,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array{missing: list<mixed>, orphaned: list<mixed>, stale: list<mixed>}  $diff
     */
    private function isEmpty(array $diff): bool
    {
        return $diff['missing'] === [] && $diff['orphaned'] === [] && $diff['stale'] === [];
    }
}
