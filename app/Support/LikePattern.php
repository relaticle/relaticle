<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Escapes a user-supplied string for use inside a LIKE/ILIKE pattern.
 *
 * Postgres treats backslash as the default LIKE escape character, so the
 * backslash has to be doubled before the wildcards are escaped with it.
 */
final readonly class LikePattern
{
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
