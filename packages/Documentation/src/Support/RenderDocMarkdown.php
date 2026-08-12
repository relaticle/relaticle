<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Spatie\LaravelMarkdown\MarkdownRenderer;

final readonly class RenderDocMarkdown
{
    public function __construct(private MarkdownRenderer $renderer) {}

    public function __invoke(string $body): string
    {
        return $this->renderer->toHtml($body);
    }
}
