<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\NodeIterator;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\Xml;

final readonly class ImageAltTextRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Image::assertInstanceOf($node);

        $altText = '';

        foreach (new NodeIterator($node) as $child) {
            if ($child instanceof StringContainerInterface) {
                $altText .= $child->getLiteral();
            }

            if ($child instanceof Newline) {
                $altText .= "\n";
            }
        }

        return Xml::escape($altText);
    }
}
