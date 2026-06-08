<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Support\Str;

final class TitleSanitizer
{
    private const string BIDI_PATTERN = '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public static function clean(string $value): string
    {
        $stripped = preg_replace(self::BIDI_PATTERN, '', $value) ?? $value;
        $collapsed = (string) preg_replace('/\s+/u', ' ', $stripped);

        return Str::limit(trim($collapsed), 200, '', preserveWords: false);
    }
}
