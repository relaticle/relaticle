<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

/**
 * Sanitizes user-authored text before it is embedded into a privileged prompt:
 * strip control characters (newlines could fabricate prompt-level lines),
 * collapse whitespace, drop quotes/backslashes (prompts wrap values in quotes),
 * and drop angle brackets (prompts wrap values in <context>/<resolved_actions>-
 * style tags -- a label containing a literal closing tag could otherwise make
 * the model treat trailing attacker text as outside the untrusted block).
 */
final class PromptText
{
    public static function sanitize(string $text, int $maxLength): string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $collapsed = preg_replace('/\s+/u', ' ', trim($stripped)) ?? '';

        return mb_substr(str_replace(['"', '\\', '<', '>'], '', $collapsed), 0, $maxLength);
    }
}
