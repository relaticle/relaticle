<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

// Keep character-identical with the checkbox renderer in resources/js/chat.js.
final readonly class TaskMarkerRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        if (! $node instanceof TaskListItemMarker) {
            return null;
        }

        $checked = $node->isChecked() ? 'true' : 'false';

        return "<span class=\"chat-md-task\" role=\"checkbox\" aria-checked=\"{$checked}\" aria-disabled=\"true\"></span>";
    }
}
