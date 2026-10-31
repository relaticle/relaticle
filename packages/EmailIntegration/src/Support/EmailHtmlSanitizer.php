<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Dom\HTMLDocument;
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
            // bounded ceiling rather than removing it. See MAX_INPUT_BYTES.
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

        return self::wrapPreviewDocument($clean, self::paintsOwnBackground($clean));
    }

    private static function paintsOwnBackground(string $html): bool
    {
        $document = HTMLDocument::createFromString('<!doctype html>'.$html, LIBXML_NOERROR);

        foreach ($document->querySelectorAll('[bgcolor]') as $node) {
            if (self::paintsSomething((string) $node->getAttribute('bgcolor'))) {
                return true;
            }
        }

        foreach ($document->querySelectorAll('[style]') as $node) {
            preg_match_all('/(?<![\w-])background(?:-color|-image)?\s*:([^;]*)/i', (string) $node->getAttribute('style'), $values);

            foreach ($values[1] as $value) {
                if (self::paintsSomething($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function paintsSomething(string $value): bool
    {
        $value = str_replace('!important', '', mb_strtolower($value));

        if (str_contains($value, 'url(') || str_contains($value, 'gradient(')) {
            return true;
        }

        // White is the page the dark reader already replaces, so pasted white spans keep a reply dark.
        $value = (string) preg_replace([
            '/(?:rgba|hsla)\((?:[^,)]*,){3}\s*(?:0(?:\.0*)?|\.0+)%?\s*\)/',
            '/(?:rgba?|hsla?)\([^)\/]*\/\s*(?:0(?:\.0*)?|\.0+)%?\s*\)/',
            '/rgba?\(\s*255\s*,?\s*255\s*,?\s*255\s*(?:[,\/]\s*[\d.]+%?\s*)?\)/',
        ], ' ', $value);
        $neutral = '/^(?:transparent|none|inherit|initial|unset|white|#fff|#ffffff|repeat|repeat-x|repeat-y|no-repeat|scroll|fixed|local|left|right|top|bottom|center|-?[\d.]+[a-z%]*)$/';

        return array_any(preg_split('/[\s,\/]+/', trim($value), flags: PREG_SPLIT_NO_EMPTY) ?: [], fn (string $token): bool => preg_match($neutral, $token) !== 1);
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

    private static function wrapPreviewDocument(string $html, bool $ownColors): string
    {
        $rootClass = $ownColors ? ' class="email-own-colors"' : '';

        return <<<HTML
<!doctype html>
<html{$rootClass}>
<head>
<meta charset="utf-8">
<meta name="color-scheme" content="light dark">
<style>
:root { color-scheme: light dark; background: #ffffff; }
html, body { margin: 0; background: #ffffff; color: #111827; }
:where(body) {
    font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 14px;
    line-height: 1.6;
}
body { box-sizing: border-box; padding: 16px; overflow-wrap: anywhere; }
img, video { max-width: 100%; height: auto; }
table { max-width: 100%; }
pre { white-space: pre-wrap; }
/* The frame canvas follows the root's used color-scheme, so it must match the app's theme too.
   Mail that paints its own background keeps it on a light page, as Apple Mail and HEY do. */
:root[data-theme="light"], :root.email-own-colors { color-scheme: light; }
:root[data-theme="dark"]:not(.email-own-colors) { color-scheme: dark; background: transparent; }
:root[data-theme="dark"]:not(.email-own-colors) body { background: transparent !important; color: #f3f4f6 !important; }
:root[data-theme="dark"]:not(.email-own-colors) body * {
    background-color: transparent !important;
    border-color: #4b5563 !important;
    color: #f3f4f6 !important;
}
:root[data-theme="dark"]:not(.email-own-colors) :is(a, a *) { color: #93c5fd !important; }
</style>
</head>
<body>
HTML
            .$html
            .'</body></html>';
    }
}
