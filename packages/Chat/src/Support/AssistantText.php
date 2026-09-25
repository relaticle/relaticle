<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

final readonly class AssistantText
{
    // Laravel\Ai\Streaming\Events\TextDelta::combine() joins step texts with this.
    private const string STEP_SEPARATOR = "\n\n";

    // A model that echoes its acknowledgment before AND after a tool call yields
    // "X.\n\nX." across steps, or "X.X." within one; natural prose never repeats whole.
    public static function collapseRepeated(string $text): string
    {
        $steps = array_unique(array_map(trim(...), explode(self::STEP_SEPARATOR, $text)));

        if (count($steps) === 1 && str_contains($text, self::STEP_SEPARATOR)) {
            return $steps[0];
        }

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

    /**
     * The reply the user should keep. A turn that called tools and then wrote
     * text keeps only the text written after the last tool call: anything the
     * model wrote before a tool call is narration ("Let me look that up") or
     * an acknowledgment it will restate once the result is in. A turn whose
     * last tool call produced no further text keeps everything, so the only
     * text it wrote is never lost.
     */
    public static function finalReply(string $fullText, string $afterLastToolCall, bool $hadToolCalls): string
    {
        if (! $hadToolCalls || trim($afterLastToolCall) === '') {
            return self::collapseRepeated($fullText);
        }

        return self::collapseRepeated(trim($afterLastToolCall));
    }
}
