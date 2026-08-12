<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Spatie\LaravelMarkdown\MarkdownRenderer;

final readonly class RenderDocMarkdown
{
    public function __construct(private MarkdownRenderer $renderer) {}

    public function __invoke(DocPage $page): string
    {
        return $this->renderer->toHtml($page->body);
    }
}
