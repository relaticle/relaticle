<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Docs\Support;

use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Renderer\Block\HeadingRenderer;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use Stringable;

final readonly class DocHeadingRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?Stringable
    {
        if (! $node instanceof Heading) {
            return null;
        }

        $permalink = $node->firstChild();

        if ($permalink instanceof HeadingPermalink && $permalink->getSlug() === $node->data->get('attributes/id', null)) {
            $node->data->remove('attributes/id');
        }

        return (new HeadingRenderer)->render($node, $childRenderer);
    }
}
