<?php

declare(strict_types=1);

namespace App\Filament\RichEditor;

use DOMElement;
use Tiptap\Nodes\TaskItem as BaseTaskItem;

final class TaskItem extends BaseTaskItem
{
    // tiptap-php declares `checked` without parseHTML, so every item would read back unticked.
    /** @return array<string, array<string, mixed>> */
    public function addAttributes(): array
    {
        return [
            'checked' => [
                'default' => false,
                'parseHTML' => fn (DOMElement $DOMNode): bool => $DOMNode->getAttribute('data-checked') === 'true',
                'renderHTML' => fn (object $attributes): array => [
                    'data-checked' => ($attributes->checked ?? false) ? 'true' : 'false',
                ],
            ],
        ];
    }
}
