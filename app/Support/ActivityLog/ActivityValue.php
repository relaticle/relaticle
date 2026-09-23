<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use App\Support\PlainText;

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

    public static function display(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['label'] ?? null;
        }

        if (is_bool($value)) {
            return $value ? __('workspaces.activity.yes') : __('workspaces.activity.no');
        }

        if (! is_scalar($value)) {
            return self::EMPTY;
        }

        $text = PlainText::fromHtml((string) $value);

        if ($text === '') {
            return self::EMPTY;
        }

        return $text;
    }
}
