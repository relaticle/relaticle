<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Docs\Support;

use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\DefaultAttributes\DefaultAttributesExtension;
use Relaticle\Cloud\Marketing\Support\LocalizedUrl;
use Spatie\LaravelMarkdown\MarkdownRenderer;

final readonly class RenderDocMarkdown
{
    private MarkdownRenderer $renderer;

    public function __construct(MarkdownRenderer $renderer)
    {
        $this->renderer = (clone $renderer)
            ->commonmarkOptions([
                ...config('markdown.commonmark_options'),
                'attributes' => ['allow' => ['id']],
                'default_attributes' => ['attributes' => [Image::class => ['loading' => 'lazy', 'decoding' => 'async']]],
            ])
            ->addExtension(new AttributesExtension)
            ->addExtension(new DefaultAttributesExtension)
            ->addBlockRenderer(Heading::class, new DocHeadingRenderer, priority: 1);
    }

    public function __invoke(string $body): string
    {
        return LocalizedUrl::localizeLinks($this->renderer->toHtml($body));
    }
}
