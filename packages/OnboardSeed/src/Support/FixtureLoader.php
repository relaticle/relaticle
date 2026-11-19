<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

final class FixtureLoader
{
    private static string $basePath;

    private static string $fixtureSet = 'sales';

    private static ?string $locale = null;

    public static function setBasePath(string $path): void
    {
        self::$basePath = $path;
    }

    public static function setFixtureSet(string $set): void
    {
        self::$fixtureSet = $set;
    }

    public static function setLocale(?string $locale): void
    {
        self::$locale = $locale;
    }

    public static function getFixtureSet(): string
    {
        return self::$fixtureSet;
    }

    public static function getBasePath(): string
    {
        if (! isset(self::$basePath)) {
            self::$basePath = dirname(__DIR__, 2).'/resources/fixtures';
        }

        return self::$basePath;
    }

    /**
     * @param  string  $type  The entity type (e.g., 'companies', 'people')
     * @return array<string, array<string, mixed>>
     *
     * @throws FileNotFoundException
     */
    public static function load(string $type): array
    {
        $path = self::resolveDirectory($type);

        if ($path === null) {
            return [];
        }

        $fixtures = [];
        $files = File::files($path);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'yaml' && $file->getExtension() !== 'yml') {
                continue;
            }

            $key = $file->getFilenameWithoutExtension();
            $content = Yaml::parse(File::get($file->getPathname()));
            $fixtures[$key] = $content;
        }

        return $fixtures;
    }

    private static function resolveDirectory(string $type): ?string
    {
        $roots = array_filter([self::$locale === null ? null : self::getBasePath().'/'.self::$locale, self::getBasePath()]);

        foreach ($roots as $root) {
            foreach (["{$root}/".self::$fixtureSet."/{$type}", "{$root}/{$type}"] as $candidate) {
                if (File::isDirectory($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public static function reset(): void
    {
        self::$locale = null;
        self::$fixtureSet = 'sales';
        self::$basePath = dirname(__DIR__, 2).'/resources/fixtures';
    }
}
