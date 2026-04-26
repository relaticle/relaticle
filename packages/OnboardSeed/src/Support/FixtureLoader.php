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

    public static function setBasePath(string $path): void
    {
        self::$basePath = $path;
    }

    public static function setFixtureSet(string $set): void
    {
        self::$fixtureSet = $set;
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
        $path = self::getBasePath().'/'.self::$fixtureSet.'/'.$type;

        if (! File::isDirectory($path)) {
            // Fall back to root-level fixtures for backward compatibility
            $path = self::getBasePath().'/'.$type;

            if (! File::isDirectory($path)) {
                return [];
            }
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

    public static function reset(): void
    {
        self::$fixtureSet = 'sales';
        self::$basePath = dirname(__DIR__, 2).'/resources/fixtures';
    }
}
