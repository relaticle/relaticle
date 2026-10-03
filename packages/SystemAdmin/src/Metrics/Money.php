<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

final readonly class Money
{
    public const string EMPTY = "\u{2014}";

    public static function format(?int $micros): string
    {
        if ($micros === null) {
            return self::EMPTY;
        }

        return ($micros < 0 ? '-' : '').'$'.number_format(abs($micros) / 1_000_000, 2);
    }
}
