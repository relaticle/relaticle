<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final readonly class EmailHtmlSanitizer
{
    /**
     * Upper bound on the HTML handed to the DOM sanitizer. Symfony's 20 KB default
     * clips real email bodies (newsletters run 50–150 KB), but lifting the cap
     * entirely lets a pathological multi-MB body turn every render into an unbounded
     * DOM parse (CPU/memory DoS). 2 MB comfortably fits any legitimate email body.
     */
    private const int MAX_INPUT_BYTES = 2_000_000;

    /**
     * Sanitize untrusted email body HTML before rendering it in an iframe.
     *
     * Strips scripts, inline event handlers (onerror, onload, ...),
     * `javascript:` URLs, and unsafe elements that a regex filter cannot
     * reliably catch, while preserving the presentational attributes/elements
     * that real-world email layouts depend on.
     */
    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        // Presentational attributes/elements emails rely on for layout.
        // CSS values are not deep-sanitized, but the body is rendered in an
        // opaque-origin sandboxed iframe, so CSS cannot read cookies or run JS.
        $config = (new HtmlSanitizerConfig)
            // Raise Symfony's 20 KB default (which clips real newsletters) to a
            // bounded ceiling rather than removing it — see MAX_INPUT_BYTES.
            ->withMaxInputLength(self::MAX_INPUT_BYTES)
            ->allowSafeElements()
            ->allowElement('style')
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowAttribute('style', '*')
            ->allowAttribute('class', '*')
            ->allowAttribute('id', '*')
            ->allowAttribute('align', '*')
            ->allowAttribute('valign', '*')
            ->allowAttribute('bgcolor', '*')
            ->allowAttribute('color', '*')
            ->allowAttribute('width', '*')
            ->allowAttribute('height', '*')
            ->allowAttribute('border', '*')
            ->allowAttribute('cellpadding', '*')
            ->allowAttribute('cellspacing', '*')
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow');

        $clean = new HtmlSanitizer($config)->sanitize($html);

        return trim($clean) === '' ? null : $clean;
    }
}
