<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

final readonly class MarkdownRenderer
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        // Above the core LinkRenderer (priority 0): a `/r/` citation becomes a
        // record chip, everything else falls through to it untouched.
        $environment->addRenderer(Link::class, new RecordChipRenderer, 10);

        $this->converter = new MarkdownConverter($environment);
    }

    public function render(string $markdown): string
    {
        return $this->converter->convert($this->normalize($markdown))->getContent();
    }

    /** Keep this normalization aligned with window.renderMarkdown. */
    private function normalize(string $markdown): string
    {
        $markdown = (string) preg_replace('/^[ \t]*(\{\{block:\d+\}\})[ \t]*$/m', "\n$1\n", $markdown);

        return (string) preg_replace('/\[([^\]]+)\]\(null\)/', '$1', $markdown);
    }
}
