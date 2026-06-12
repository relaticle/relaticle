<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

final class LikePattern
{
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
