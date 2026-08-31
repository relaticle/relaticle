<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use Illuminate\Support\Str;

/**
 * Renders one side of a logged change as the short plain-text value a reader sees.
 *
 * Rich-editor fields are logged as their raw HTML, so an untouched editor arrives
 * here as `<p></p>` and printed verbatim it read as a change from nothing to markup.
 * Stripping first also makes an empty editor compare equal to an empty value, which
 * is what drops the phantom line instead of merely tidying it.
 */
final readonly class ActivityValue
{
    public const string EMPTY = '—';

    /**
     * Block boundaries carry the only whitespace in `<p>a</p><p>b</p>`, so they
     * become a space before the tags go — otherwise two paragraphs read as "ab".
     */
    private const string BLOCK_BOUNDARY = '/<\s*br\s*\/?\s*>|<\s*\/\s*(?:p|div|li|tr|h[1-6]|blockquote)\s*>/i';

    public static function display(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['label'] ?? null;
        }

        if (is_bool($value)) {
            return $value ? __('teams.activity.yes') : __('teams.activity.no');
        }

        if (! is_scalar($value)) {
            return self::EMPTY;
        }

        $text = (string) preg_replace(self::BLOCK_BOUNDARY, ' ', (string) $value);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = Str::squish(str_replace("\u{00A0}", ' ', $text));

        if ($text === '') {
            return self::EMPTY;
        }

        return $text;
    }
}
