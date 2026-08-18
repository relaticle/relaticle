<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Support\Str;

final class TitleSanitizer
{
    private const string BIDI_PATTERN = '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * Matched as a `/u` character class rather than passed to trim(): trim()
     * compares bytes, so a UTF-8 quote list also eats the trailing byte of any
     * multibyte letter that shares one — "…ś" became invalid UTF-8.
     */
    private const string WRAPPING_QUOTES = '["\'`“”„‟«»‘’‚‛\s]';

    public static function clean(string $value): string
    {
        $stripped = preg_replace(self::BIDI_PATTERN, '', $value) ?? $value;
        $collapsed = (string) preg_replace('/\s+/u', ' ', $stripped);

        return Str::limit(trim($collapsed), 200, '', preserveWords: false);
    }

    /**
     * Sanitize a model-written title.
     *
     * On top of `clean()` this removes the decorations a titling model adds
     * even when told not to — wrapping quotes, a "Title:" style prefix, a
     * closing full stop — and enforces a sidebar-sized cap rather than the
     * 200-character ceiling a human rename gets.
     */
    public static function generated(string $value): string
    {
        $title = self::clean($value);
        $title = (string) preg_replace('/^(?:title|chat title|conversation title)\s*[:\-–]\s*/iu', '', $title);
        $title = (string) preg_replace('/^'.self::WRAPPING_QUOTES.'+|'.self::WRAPPING_QUOTES.'+$/u', '', $title);
        $title = (string) preg_replace('/[.,;:!]+$/u', '', $title);

        return Str::limit(trim($title), 60, '', preserveWords: false);
    }
}
