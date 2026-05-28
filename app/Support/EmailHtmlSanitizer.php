<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class EmailHtmlSanitizer
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

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow');

        $clean = new HtmlSanitizer($config)->sanitize($html);

        return trim($clean) === '' ? null : $clean;
    }
}
