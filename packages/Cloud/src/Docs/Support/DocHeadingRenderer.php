<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Docs\Support;

use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final readonly class DocHeadingRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): null
    {
        $permalink = $node->firstChild();

        if ($permalink instanceof HeadingPermalink && $permalink->getSlug() === $node->data->get('attributes/id', null)) {
            $node->data->remove('attributes/id');
        }

        return null;
    }
}
