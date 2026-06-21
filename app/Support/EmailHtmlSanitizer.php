<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final readonly class EmailHtmlSanitizer
{
    /**
     * Sanitize untrusted email body HTML before rendering it in an iframe.
     *
     * Strips scripts, inline event handlers (onerror, onload, ...),
     * `javascript:` URLs, and unsafe elements that a regex filter cannot
     * reliably catch.
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
            // Symfony defaults to truncating input at 20 KB, which silently
            // clips real email bodies (newsletters routinely run 50–150 KB) to a
            // fraction of their content. The body is already size-bounded at
            // ingestion, so lift the cap and let the full message render.
            ->withMaxInputLength(-1)
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
