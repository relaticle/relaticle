<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

final class AssistantText
{
    /**
     * Collapse an assistant message that the multi-step agent loop emitted as the
     * same text repeated back-to-back (laravel/ai concatenates text deltas across
     * every step; a model that echoes its acknowledgment before AND after a tool
     * call yields "X.X."). When the whole string is exactly one unit repeated 2+
     * times, returns the smallest such unit; otherwise returns the text unchanged.
     * Natural prose is effectively never an exact whole-string repetition, so a
     * legitimate message is not at risk of being collapsed.
     */
    public static function collapseRepeated(string $text): string
    {
        $length = strlen($text);

        if ($length < 2) {
            return $text;
        }

        for ($unitLength = 1; $unitLength <= intdiv($length, 2); $unitLength++) {
            if ($length % $unitLength !== 0) {
                continue;
            }

            $unit = substr($text, 0, $unitLength);

            if (str_repeat($unit, intdiv($length, $unitLength)) === $text) {
                return $unit;
            }
        }

        return $text;
    }
}
