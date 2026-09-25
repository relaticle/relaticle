<?php

declare(strict_types=1);

namespace App\Support;

use BladeUI\Icons\Factory;
use RuntimeException;

/**
 * Reads the `d` attribute out of a Blade icon's SVG.
 *
 * Chat draws a record chip as raw HTML in two places, a CommonMark renderer and
 * its mirror in chat.js, so neither can use a Blade component and both used to
 * carry hand-copied path data. This keeps the icon set in one place:
 * App\Enums\CrmEntity. BladeUI's own Factory memoises each parsed SVG, so there
 * is nothing to cache here.
 */
final readonly class IconPath
{
    public static function for(string $icon): string
    {
        $svg = resolve(Factory::class)->svg($icon)->contents();

        throw_if(
            preg_match_all('/\sd="([^"]+)"/', $svg, $matches) !== 1,
            RuntimeException::class,
            "Icon [{$icon}] must have exactly one path to be usable as raw path data.",
        );

        return $matches[1][0];
    }
}
