<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

final class FixtureLoader
{
    /**
     * Base path for fixtures
     */
    private static string $basePath;

    /**
     * Set the base path for fixtures
     */
    public static function setBasePath(string $path): void
    {
        self::$basePath = $path;
    }

    /**
     * Get the base path for fixtures
     */
    public static function getBasePath(): string
    {
        if (! isset(self::$basePath)) {
            self::$basePath = dirname(__DIR__, 2).'/resources/fixtures';
        }

        return self::$basePath;
    }

    /**
     * Load fixtures for a specific entity type
     *
     * @param  string  $type  The entity type (e.g., 'companies', 'people')
     * @return array<string, array<string, mixed>> The loaded fixtures
     *
     * @throws FileNotFoundException
     */
    public static function load(string $type): array
    {
        $path = self::getBasePath().'/'.$type;

        if (! File::isDirectory($path)) {
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
}
