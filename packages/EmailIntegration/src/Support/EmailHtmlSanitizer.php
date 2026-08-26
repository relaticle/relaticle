<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Relaticle\EmailIntegration\Models\EmailAttachment;
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
     *
     * @param  iterable<int, EmailAttachment>  $inlineAttachments
     */
    public static function sanitize(?string $html, iterable $inlineAttachments = []): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $html = self::replaceCidImages($html, $inlineAttachments);

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

        if (trim($clean) === '') {
            return null;
        }

        return self::wrapPreviewDocument($clean);
    }

    /**
     * @param  iterable<int, EmailAttachment>  $inlineAttachments
     */
    private static function replaceCidImages(string $html, iterable $inlineAttachments): string
    {
        $urlsByContentId = [];

        foreach ($inlineAttachments as $attachment) {
            if (! $attachment->is_inline) {
                continue;
            }

            if (blank($attachment->content_id)) {
                continue;
            }

            $urlsByContentId[mb_strtolower(trim((string) $attachment->content_id, '<>'))] = route('email-attachments.inline', $attachment->getKey());
        }

        if ($urlsByContentId === []) {
            return $html;
        }

        return preg_replace_callback('/cid:([^"\'\s>)]+)/i', function (array $matches) use ($urlsByContentId): string {
            $contentId = mb_strtolower(trim(rawurldecode($matches[1]), '<>'));

            return $urlsByContentId[$contentId] ?? $matches[0];
        }, $html) ?? $html;
    }

    private static function wrapPreviewDocument(string $html): string
    {
        return <<<'HTML'
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="color-scheme" content="light dark">
<style>
:root { color-scheme: light dark; background: #ffffff; }
html, body { margin: 0; background: #ffffff; color: #111827; }
body {
    box-sizing: border-box;
    padding: 0;
    overflow-wrap: anywhere;
}
img, video { max-width: 100%; height: auto; }
table { max-width: 100%; }
pre { white-space: pre-wrap; }
@media (prefers-color-scheme: dark) {
    :root { background: #17181a; }
    html, body { background: #17181a !important; color: #f3f4f6 !important; }
    body, table, tbody, thead, tfoot, tr, td, th, div, p, span, section, article, main, blockquote, li {
        background-color: transparent !important;
        border-color: #4b5563 !important;
        color: #f3f4f6 !important;
    }
    a { color: #93c5fd !important; }
    [bgcolor] { background-color: transparent !important; }
}
</style>
</head>
<body>
HTML
            .$html
            .'</body></html>';
    }
}
