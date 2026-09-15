<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Str;

final class FakeTranslations
{
    /**
     * Registers a real JSON path so lookups run the same loader the shipped
     * lang/<code>.json files do. Lang::addLines cannot: it splits the key on
     * its own dots, so a key like "Ask anything..." never resolves.
     *
     * @param  array<string, string>  $lines
     */
    public static function inLocale(string $locale, array $lines): void
    {
        $directory = sys_get_temp_dir().'/chat-locale-test-'.Str::random(8);

        mkdir($directory);
        file_put_contents($directory.'/'.$locale.'.json', json_encode($lines, JSON_THROW_ON_ERROR));

        resolve(Translator::class)->addJsonPath($directory);
    }
}
