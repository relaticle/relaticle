<?php

declare(strict_types=1);

namespace App\Support;

use Locale;

/**
 * The one list the profile select, the validation rule and the chat locale
 * scope all read, so a language is either fully supported or absent.
 */
final readonly class ChatLocales
{
    public const string DEFAULT = 'en';

    /** @var list<string> */
    public const array CODES = ['en', 'ar', 'da', 'de', 'es', 'fa', 'fr', 'it', 'nl', 'pt', 'ru', 'tr', 'vi', 'zh'];

    public static function isSupported(?string $code): bool
    {
        if ($code === null) {
            return false;
        }

        return in_array($code, self::CODES, true);
    }

    /**
     * Each language named in itself, so a user finds theirs without knowing
     * its English name.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::CODES as $code) {
            $options[$code] = Locale::getDisplayLanguage($code, $code);
        }

        return $options;
    }

    public static function languageName(?string $code): string
    {
        return Locale::getDisplayLanguage($code ?? self::DEFAULT, 'en');
    }
}
