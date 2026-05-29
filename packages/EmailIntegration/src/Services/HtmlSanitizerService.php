<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final readonly class HtmlSanitizerService
{
    /**
     * Sanitize user-authored rich text (e.g. email signatures) for inline
     * rendering in the page DOM. Drops scripts, event handlers, and any
     * element/attribute outside the formatting allowlist.
     */
    public function sanitizeRichText(string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank');

        return new HtmlSanitizer($config)->sanitize($html);
    }
}
