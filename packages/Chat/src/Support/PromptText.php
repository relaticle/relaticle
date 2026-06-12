<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

/**
 * Sanitizes user-authored text before it is embedded into a privileged prompt:
 * strip control characters (newlines could fabricate prompt-level lines),
 * collapse whitespace, drop quotes/backslashes (prompts wrap values in quotes).
 */
final class PromptText
{
    public static function sanitize(string $text, int $maxLength): string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $collapsed = preg_replace('/\s+/u', ' ', trim($stripped)) ?? '';

        return mb_substr(str_replace(['"', '\\'], '', $collapsed), 0, $maxLength);
    }
}
