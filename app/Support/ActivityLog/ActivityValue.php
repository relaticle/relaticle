<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use App\Support\PlainText;
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

    public const string REDACTED = '••••••';

    public const int INLINE_LENGTH = 120;

    public const int EXCERPT_LENGTH = 500;

    public static function display(mixed $value): string
    {
        return self::text($value, PlainText::fromHtml(...));
    }

    public static function excerpt(string $text): string
    {
        return Str::limit($text, self::EXCERPT_LENGTH, '… (shortened)');
    }

    public static function forAgent(mixed $side): ?string
    {
        if (! is_array($side) || ($side['value'] ?? null) === null) {
            return null;
        }

        $text = self::display($side);

        return $text === self::EMPTY ? null : self::excerpt($text);
    }

    /**
     * @return array{label: string, old: string, new: string, full: array{old: string, new: string}|null}
     */
    public static function row(string $label, mixed $old, mixed $new): array
    {
        $full = [
            'old' => self::text($old, PlainText::linesFromHtml(...)),
            'new' => self::text($new, PlainText::linesFromHtml(...)),
        ];

        $before = str_replace("\n", ' ', $full['old']);
        $after = str_replace("\n", ' ', $full['new']);

        if (max(mb_strlen($before), mb_strlen($after)) <= self::INLINE_LENGTH) {
            return ['label' => $label, 'old' => $before, 'new' => $after, 'full' => null];
        }

        return [
            'label' => $label,
            'old' => Str::limit($before, self::INLINE_LENGTH),
            'new' => Str::limit($after, self::INLINE_LENGTH),
            'full' => $full,
        ];
    }

    /**
     * @param  array{label: string, old: string, new: string, full: array{old: string, new: string}|null}  $row
     */
    public static function isUnchanged(array $row): bool
    {
        $sides = $row['full'] ?? $row;

        return $sides['old'] === $sides['new'];
    }

    /**
     * @param  callable(string): string  $plain
     */
    private static function text(mixed $value, callable $plain): string
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

        $text = $plain((string) $value);

        return $text === '' ? self::EMPTY : $text;
    }
}
