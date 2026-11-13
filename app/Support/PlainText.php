<?php

declare(strict_types=1);

namespace App\Support;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Illuminate\Support\Str;

final readonly class PlainText
{
    /**
     * Block boundaries carry the only whitespace in `<p>a</p><p>b</p>`, so they
     * become a space before the tags go. Otherwise two paragraphs read as "ab".
     */
    private const string BLOCK_BOUNDARY = '/<\s*br\s*\/?\s*>|<\s*\/\s*(?:p|div|li|tr|h[1-6]|blockquote)\s*>/i';

    // A browser's default stylesheet gives these a vertical margin, so they read as
    // paragraphs. Gmail writes one <div> per line, so a div only starts a new line.
    private const array PARAGRAPH_ELEMENTS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'table', 'hr', 'figure', 'dl'];

    private const array LINE_ELEMENTS = ['div', 'tr', 'dt', 'dd', 'section', 'article', 'header', 'footer', 'address', 'center', 'main', 'aside', 'nav', 'caption'];

    private const array CELL_ELEMENTS = ['td', 'th'];

    // Boundary markers resolved in tidy(): adjacent line blocks must not add up to a blank line.
    private const string LINE_BREAK = "\x1F";

    private const string PARAGRAPH_BREAK = "\x1E";

    private const array HIDDEN_ELEMENTS = ['title', 'style', 'script', 'template', 'noscript'];

    public static function fromHtml(string $html): string
    {
        return self::squished(self::markTaskItems($html));
    }

    public static function fromHtmlWithoutTaskMarkers(string $html): string
    {
        return self::squished($html);
    }

    public static function linesFromHtml(string $html): string
    {
        return collect(preg_split(self::BLOCK_BOUNDARY, self::markTaskItems($html)) ?: [])
            ->flatMap(fn (string $block): array => explode("\n", $block))
            ->map(self::squished(...))
            ->filter(fn (string $line): bool => $line !== '')
            ->implode("\n");
    }

    public static function paragraphsFromHtml(string $html): string
    {
        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');

        return self::tidy(self::textOf($document->body));
    }

    private static function squished(string $html): string
    {
        $text = (string) preg_replace(self::BLOCK_BOUNDARY, ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

        return Str::squish(str_replace("\u{00A0}", ' ', $text));
    }

    private static function markTaskItems(string $html): string
    {
        if (! str_contains($html, 'taskItem')) {
            return $html;
        }

        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');

        foreach ($document->querySelectorAll(ChecklistHtml::ITEM) as $item) {
            $item->prepend(self::taskMarker($item->matches(ChecklistHtml::CHECKED_ITEM)));
        }

        return $document->body->innerHTML;
    }

    private static function taskMarker(bool $checked): string
    {
        return $checked ? '[x] ' : '[ ] ';
    }

    private static function textOf(Node $parent): string
    {
        $text = '';

        foreach ($parent->childNodes as $child) {
            $text .= match (true) {
                // Not \s: under a UTF-8 locale PCRE counts byte 0xA0 as space and splits letters such as à.
                $child instanceof Text => (string) preg_replace('/[\t\n\f\r ]+/', ' ', str_replace([self::LINE_BREAK, self::PARAGRAPH_BREAK, "\u{00A0}"], ['', '', ' '], $child->data)),
                $child instanceof Element => self::elementText($child),
                default => '',
            };
        }

        return $text;
    }

    private static function elementText(Element $element): string
    {
        $name = $element->localName;

        return match (true) {
            in_array($name, self::HIDDEN_ELEMENTS, true) => '',
            $name === 'br' => "\n",
            $name === 'a' => self::linkText($element),
            in_array($name, ['ul', 'ol'], true) => self::PARAGRAPH_BREAK.self::listText($element).self::PARAGRAPH_BREAK,
            $name === 'blockquote' => self::PARAGRAPH_BREAK.self::quoted(self::tidy(self::textOf($element))).self::PARAGRAPH_BREAK,
            in_array($name, self::CELL_ELEMENTS, true) => self::textOf($element).' ',
            in_array($name, self::PARAGRAPH_ELEMENTS, true) => self::PARAGRAPH_BREAK.self::textOf($element).self::PARAGRAPH_BREAK,
            in_array($name, self::LINE_ELEMENTS, true) => self::LINE_BREAK.self::textOf($element).self::LINE_BREAK,
            default => self::textOf($element),
        };
    }

    private static function linkText(Element $link): string
    {
        $label = self::textOf($link);
        $href = trim((string) $link->getAttribute('href'));

        if (preg_match('/^(https?|mailto):/i', $href) !== 1) {
            return $label;
        }

        $target = (string) preg_replace('/^mailto:/i', '', $href);

        if (trim($label) === '') {
            return $target;
        }

        return str_contains($label, $target) ? $label : "{$label} ({$target})";
    }

    private static function listText(Element $list): string
    {
        $items = [];

        foreach ($list->children as $child) {
            if ($child->localName !== 'li') {
                $items[] = self::lines(self::elementText($child));

                continue;
            }

            $marker = $list->localName === 'ol' ? (count($items) + 1).'. ' : '- ';

            if ($child->matches(ChecklistHtml::ITEM)) {
                $marker .= self::taskMarker($child->matches(ChecklistHtml::CHECKED_ITEM));
            }

            $items[] = $marker.self::lines(self::textOf($child));
        }

        return implode("\n", array_filter($items, fn (string $item): bool => $item !== ''));
    }

    private static function quoted(string $text): string
    {
        return collect(explode("\n", $text))
            ->map(fn (string $line): string => rtrim("> {$line}"))
            ->implode("\n");
    }

    private static function lines(string $text): string
    {
        return (string) preg_replace('/\n+/', "\n", self::tidy($text));
    }

    private static function tidy(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/[ \t]*[\x1E\x1F][\x1E\x1F \t]*/',
            fn (array $breaks): string => str_contains($breaks[0], self::PARAGRAPH_BREAK) ? "\n\n" : "\n",
            $text,
        );
        $text = (string) preg_replace('/[ \t]*\n[ \t]*/', "\n", $text);

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }
}
