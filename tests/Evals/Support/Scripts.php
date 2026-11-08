<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

final readonly class Scripts
{
    private const string NON_LATIN = '/[\p{Cyrillic}\p{Arabic}\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Hangul}]/u';

    public static function isLatin(string $text): bool
    {
        $letters = (int) preg_match_all('/\p{L}/u', $text);

        if ($letters === 0) {
            return true;
        }

        $other = (int) preg_match_all(self::NON_LATIN, $text);

        return $other / $letters < 0.1;
    }
}
