<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Spatie\LaravelMarkdown\MarkdownRenderer;

final readonly class RenderDocMarkdown
{
    public function __construct(private MarkdownRenderer $renderer) {}

    /**
     * Article images live below the fold behind a text-first layout, so
     * they're marked lazy at render time -- the renderer has no config
     * hook for image attributes, and a post-replace on the one tag shape
     * CommonMark emits beats a custom node renderer.
     */
    public function __invoke(string $body): string
    {
        return str_replace('<img ', '<img loading="lazy" decoding="async" ', $this->renderer->toHtml($body));
    }
}
