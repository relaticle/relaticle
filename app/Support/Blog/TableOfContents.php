<?php

declare(strict_types=1);

namespace App\Support\Blog;

use DOMDocument;
use DOMElement;
use DOMXPath;

final readonly class TableOfContents
{
    /**
     * Build a fragment => heading-text map from rendered post HTML.
     *
     * The heading text is taken from the element's text content rather than a
     * regex capture, so headings containing inline markup (bold, inline code,
     * links) keep their full label instead of being cut at the first tag.
     *
     * @return array<string, string>
     */
    public static function fromHtml(string $html, string $tag = 'h2'): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?><div>'.$html.'</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $toc = [];

        foreach (new DOMXPath($document)->query('//'.$tag) ?: [] as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }

            $fragment = self::fragmentFor($heading);
            $text = self::textFor($heading);
            if ($fragment === null) {
                continue;
            }
            if ($text === '') {
                continue;
            }

            $toc[$fragment] = $text;
        }

        return $toc;
    }

    /**
     * Prefer the permalink anchor's id — the markdown renderer slugifies the
     * heading's own id from its inner HTML, which yields unusable fragments.
     */
    private static function fragmentFor(DOMElement $heading): ?string
    {
        foreach ($heading->getElementsByTagName('a') as $anchor) {
            $id = $anchor->getAttribute('id');

            if ($id !== '') {
                return $id;
            }
        }

        $ownId = $heading->getAttribute('id');

        return $ownId === '' ? null : $ownId;
    }

    /**
     * The permalink anchor injects a "#" symbol into the heading; strip it so the
     * entry reads the way the heading does.
     */
    private static function textFor(DOMElement $heading): string
    {
        $clone = $heading->cloneNode(true);

        if (! $clone instanceof DOMElement) {
            return '';
        }

        foreach (iterator_to_array($clone->getElementsByTagName('a')) as $anchor) {
            if ($anchor->getAttribute('id') !== '') {
                $anchor->parentNode?->removeChild($anchor);
            }
        }

        return trim(preg_replace('/\s+/u', ' ', $clone->textContent) ?? '');
    }
}
