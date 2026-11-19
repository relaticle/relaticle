<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

#[Description('Write the English sentence keys found in source to lang/en.json and each package lang/en.json')]
#[Signature('lang:extract
        {--check : Write nothing and exit 1 when a catalogue differs from the source}')]
final class LangExtractCommand extends Command
{
    private const string CALL_PATTERN = '/(?<![\w$>:\\\\])(?:@lang|__|trans_choice|trans|Lang::(?:get|string|choice))\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*[,)]/s';

    private const string NAMESPACE_PATTERN = '/^[A-Za-z0-9_-]+::/';

    private const string SKIPPED_PACKAGE = 'SystemAdmin';

    /** @var list<string>|null */
    private ?array $rootGroups = null;

    public function handle(): int
    {
        $catalogues = $this->catalogues();
        $stale = [];

        foreach ($catalogues as $path => $keys) {
            $contents = $this->encode($keys);

            if (is_file($path) && file_get_contents($path) === $contents) {
                continue;
            }

            $stale[] = $path;

            if (! $this->option('check')) {
                file_put_contents($path, $contents);
            }
        }

        foreach ($stale as $path) {
            $this->line(($this->option('check') ? 'Out of date: ' : 'Wrote: ').$this->relative($path));
        }

        if ($stale === []) {
            $this->info('English catalogues match the source.');
        }

        return $this->option('check') && $stale !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, list<string>>
     */
    private function catalogues(): array
    {
        $catalogues = [base_path('lang/en.json') => []];

        foreach (glob(base_path('packages/*/lang'), GLOB_ONLYDIR) ?: [] as $directory) {
            if (basename(dirname($directory)) !== self::SKIPPED_PACKAGE) {
                $catalogues[$directory.'/en.json'] = [];
            }
        }

        foreach ($this->sourceFiles() as $file) {
            $relative = $this->relative($file->getPathname());

            foreach ($this->sentenceKeys($file, $relative) as $key) {
                $catalogues[$this->catalogueFor($relative)][] = $key;
            }
        }

        return array_map(static fn (array $keys): array => array_values(array_unique($keys)), $catalogues);
    }

    private function catalogueFor(string $relative): string
    {
        $package = $this->packageOf($relative);

        if ($package !== null && is_dir(base_path("packages/{$package}/lang"))) {
            return base_path("packages/{$package}/lang/en.json");
        }

        return base_path('lang/en.json');
    }

    /**
     * @return list<string>
     */
    private function sentenceKeys(SplFileInfo $file, string $relative): array
    {
        preg_match_all(self::CALL_PATTERN, $file->getContents(), $matches, PREG_SET_ORDER);

        $keys = [];

        foreach ($matches as $match) {
            $key = $this->literal($match);

            if ($key !== null && $key !== '' && ! $this->isGroupKey($key, $relative)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  array<int, string>  $match
     */
    private function literal(array $match): ?string
    {
        if (($match[1] ?? '') !== '') {
            return strtr($match[1], ["\\'" => "'", '\\\\' => '\\']);
        }

        $double = $match[2] ?? '';

        return preg_match('/(?<!\\\\)\$/', $double) === 1 ? null : stripcslashes($double);
    }

    private function isGroupKey(string $key, string $relative): bool
    {
        if (preg_match(self::NAMESPACE_PATTERN, $key) === 1) {
            return true;
        }

        $group = strstr($key, '.', true);

        return $group !== false && in_array($group, $this->groupsFor($relative), true);
    }

    /**
     * @return list<string>
     */
    private function groupsFor(string $relative): array
    {
        $this->rootGroups ??= $this->groupsIn(base_path('lang/en'));
        $package = $this->packageOf($relative);

        if ($package === null) {
            return $this->rootGroups;
        }

        return [
            ...$this->rootGroups,
            ...$this->groupsIn(base_path("packages/{$package}/lang/en")),
            ...$this->groupsIn(base_path("packages/{$package}/resources/lang/en")),
        ];
    }

    /**
     * @return list<string>
     */
    private function groupsIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $groups = [];

        foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
            $groups[] = str_replace('\\', '/', mb_substr($file->getRelativePathname(), 0, -4));
        }

        return $groups;
    }

    private function packageOf(string $relative): ?string
    {
        return preg_match('#^packages/([^/]+)/#', $relative, $match) === 1 ? $match[1] : null;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function sourceFiles(): iterable
    {
        $directories = [base_path('app'), base_path('bootstrap'), base_path('resources')];

        foreach (glob(base_path('packages/*'), GLOB_ONLYDIR) ?: [] as $package) {
            if (basename($package) !== self::SKIPPED_PACKAGE) {
                $directories[] = $package.'/src';
                $directories[] = $package.'/resources';
            }
        }

        $directories = array_filter($directories, is_dir(...));

        return $directories === []
            ? []
            : (new Finder)->files()->in($directories)->name(['*.php', '*.js', '*.ts'])->exclude(['lang', 'node_modules', 'dist', 'cache'])->sortByName();
    }

    /**
     * @param  list<string>  $keys
     */
    private function encode(array $keys): string
    {
        $keys = array_map(strval(...), $keys);
        sort($keys, SORT_STRING);

        return json_encode(
            array_combine($keys, $keys),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR,
        )."\n";
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }
}
