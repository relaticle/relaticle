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

    private const array HIDDEN_ELEMENTS = ['head', 'title', 'style', 'script', 'template', 'noscript', 'img'];

    public static function fromHtml(string $html): string
    {
        $text = (string) preg_replace(self::BLOCK_BOUNDARY, ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

        return Str::squish(str_replace("\u{00A0}", ' ', $text));
    }

    public static function linesFromHtml(string $html): string
    {
        return collect(preg_split(self::BLOCK_BOUNDARY, $html) ?: [])
            ->flatMap(fn (string $block): array => explode("\n", $block))
            ->map(self::fromHtml(...))
            ->filter(fn (string $line): bool => $line !== '')
            ->implode("\n");
    }

    public static function paragraphsFromHtml(string $html): string
    {
        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');

        return self::tidy(self::textOf($document->body));
    }

    private static function textOf(Node $parent): string
    {
        $text = '';

        foreach ($parent->childNodes as $child) {
            $text .= match (true) {
                $child instanceof Text => (string) preg_replace('/\s+/', ' ', str_replace("\u{00A0}", ' ', $child->data)),
                $child instanceof Element => self::elementText($child),
                default => '',
            };
        }

        return $text;
    }

    private static function elementText(Element $element): string
    {
        $name = strtolower($element->localName);

        return match (true) {
            in_array($name, self::HIDDEN_ELEMENTS, true) => '',
            $name === 'br' => "\n",
            $name === 'a' => self::linkText($element),
            in_array($name, ['ul', 'ol'], true) => "\n\n".self::lines(self::textOf($element))."\n\n",
            $name === 'li' => "\n".self::listMarker($element).self::lines(self::textOf($element))."\n",
            $name === 'blockquote' => "\n\n".self::quoted(self::tidy(self::textOf($element)))."\n\n",
            in_array($name, ['td', 'th'], true) => self::textOf($element).' ',
            in_array($name, self::PARAGRAPH_ELEMENTS, true) => "\n\n".self::textOf($element)."\n\n",
            in_array($name, self::LINE_ELEMENTS, true) => "\n".self::textOf($element)."\n",
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

    private static function listMarker(Element $item): string
    {
        if (strtolower((string) $item->parentElement?->localName) !== 'ol') {
            return '- ';
        }

        $position = 1;

        for ($sibling = $item->previousElementSibling; $sibling instanceof Element; $sibling = $sibling->previousElementSibling) {
            $position++;
        }

        return "{$position}. ";
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
        $text = (string) preg_replace('/[ \t]*\n[ \t]*/', "\n", $text);

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }
}
