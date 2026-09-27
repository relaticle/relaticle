<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final readonly class PlainText
{
    /**
     * Block boundaries carry the only whitespace in `<p>a</p><p>b</p>`, so they
     * become a space before the tags go. Otherwise two paragraphs read as "ab".
     */
    private const string BLOCK_BOUNDARY = '/<\s*br\s*\/?\s*>|<\s*\/\s*(?:p|div|li|tr|h[1-6]|blockquote)\s*>/i';

    public static function fromHtml(string $html): string
    {
        $text = (string) preg_replace(self::BLOCK_BOUNDARY, ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

        return Str::squish(str_replace("\u{00A0}", ' ', $text));
    }
}
