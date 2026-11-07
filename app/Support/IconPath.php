<?php

declare(strict_types=1);

namespace App\Support;

use BladeUI\Icons\Factory;
use RuntimeException;

// Chat's CommonMark renderer and chat.js draw an icon as one raw `d`, so every
// shape in the SVG is rewritten as path data and joined.
final readonly class IconPath
{
    private const string NUMBER = '[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?';

    public static function for(string $icon): string
    {
        $svg = resolve(Factory::class)->svg($icon)->contents();

        preg_match_all('/<(\w+)\b([^>]*)>/', $svg, $elements, PREG_SET_ORDER);

        $paths = [];

        foreach ($elements as [, $tag, $attributes]) {
            if ($tag === 'svg') {
                continue;
            }

            $paths[] = self::shapeToPath($icon, $tag, self::attributes($attributes));
        }

        throw_if($paths === [], RuntimeException::class, "Icon [{$icon}] has no shape to use as raw path data.");

        return implode(' ', $paths);
    }

    /** @param array<string, string> $a */
    private static function shapeToPath(string $icon, string $tag, array $a): string
    {
        return match ($tag) {
            'path' => self::absoluteStart($a['d']),
            'circle' => self::ellipse((float) $a['cx'], (float) $a['cy'], (float) $a['r'], (float) $a['r']),
            'ellipse' => self::ellipse((float) $a['cx'], (float) $a['cy'], (float) $a['rx'], (float) $a['ry']),
            'rect' => self::rect($a),
            'line' => 'M'.self::n($a['x1']).' '.self::n($a['y1']).'L'.self::n($a['x2']).' '.self::n($a['y2']),
            'polyline' => self::polyline($a['points']),
            'polygon' => self::polyline($a['points']).'Z',
            default => throw new RuntimeException("Icon [{$icon}] has a <{$tag}> that cannot be drawn as raw path data."),
        };
    }

    /** @return array<string, string> */
    private static function attributes(string $source): array
    {
        preg_match_all('/([\w-]+)="([^"]*)"/', $source, $matches);

        return array_combine($matches[1], $matches[2]);
    }

    // A leading relative `m` reads from the origin only while it opens the `d`.
    // Joined after another shape it would read from that shape's end point.
    private static function absoluteStart(string $d): string
    {
        $number = self::NUMBER;

        if (preg_match("/^\s*m\s*({$number})[\s,]*({$number})(.*)$/s", $d, $match) !== 1) {
            return trim($d);
        }

        $rest = ltrim($match[3], " \t\n,");
        $continuesWithPairs = preg_match("/^{$number}/", $rest) === 1;

        return "M{$match[1]} {$match[2]}".($continuesWithPairs ? 'l' : '').$rest;
    }

    private static function ellipse(float $cx, float $cy, float $rx, float $ry): string
    {
        $arc = 'a'.self::n($rx).' '.self::n($ry).' 0 1 0 ';

        return 'M'.self::n($cx - $rx).' '.self::n($cy).$arc.self::n(2 * $rx).' 0'.$arc.self::n(-2 * $rx).' 0';
    }

    /** @param array<string, string> $a */
    private static function rect(array $a): string
    {
        $x = (float) ($a['x'] ?? 0);
        $y = (float) ($a['y'] ?? 0);
        $width = (float) $a['width'];
        $height = (float) $a['height'];
        $rx = min((float) ($a['rx'] ?? $a['ry'] ?? 0), $width / 2);
        $ry = min((float) ($a['ry'] ?? $a['rx'] ?? 0), $height / 2);

        if ($rx === 0.0 || $ry === 0.0) {
            return 'M'.self::n($x).' '.self::n($y).'h'.self::n($width).'v'.self::n($height).'h'.self::n(-$width).'Z';
        }

        $corner = fn (float $dx, float $dy): string => 'a'.self::n($rx).' '.self::n($ry).' 0 0 1 '.self::n($dx).' '.self::n($dy);

        return 'M'.self::n($x + $rx).' '.self::n($y)
            .'h'.self::n($width - 2 * $rx).$corner($rx, $ry)
            .'v'.self::n($height - 2 * $ry).$corner(-$rx, $ry)
            .'h'.self::n(-($width - 2 * $rx)).$corner(-$rx, -$ry)
            .'v'.self::n(-($height - 2 * $ry)).$corner($rx, -$ry)
            .'Z';
    }

    private static function polyline(string $points): string
    {
        $coordinates = preg_split('/[\s,]+/', trim($points)) ?: [];

        return 'M'.implode(' ', array_map(self::n(...), $coordinates));
    }

    private static function n(float|string $value): string
    {
        $rounded = round((float) $value, 3);

        return rtrim(rtrim(number_format($rounded, 3, '.', ''), '0'), '.') ?: '0';
    }
}
