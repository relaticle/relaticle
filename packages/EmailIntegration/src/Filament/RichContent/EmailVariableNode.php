<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\RichContent;

use DOMElement;
use Tiptap\Core\Node;

final class EmailVariableNode extends Node
{
    /** @var string */
    public static $name = 'emailVariable';

    /** @return array<array<string, mixed>> */
    public function parseHTML(): array
    {
        return [['tag' => 'span[data-type="emailVariable"]']];
    }

    /** @return array<string, array<string, mixed>> */
    public function addAttributes(): array
    {
        return [
            'code' => ['default' => '', 'parseHTML' => fn (DOMElement $node): string => $node->getAttribute('data-code')],
            'fallback' => ['default' => '', 'parseHTML' => fn (DOMElement $node): string => $node->getAttribute('data-fallback')],
        ];
    }

    /**
     * @param  array<string, mixed>  $HTMLAttributes
     * @return array<mixed>
     */
    public function renderHTML(mixed $node, array $HTMLAttributes = []): array
    {
        $code = $node->attrs->code ?? '';
        $fallback = $node->attrs->fallback ?? '';

        return ['span', ['data-type' => self::$name, 'data-code' => $code, 'data-fallback' => $fallback], 0];
    }

    public function renderText(mixed $node): string
    {
        $code = $node->attrs->code ?? '';
        $fallback = $node->attrs->fallback ?? '';

        return e('{{ '.$code.($fallback !== '' ? ' | '.$fallback : '').' }}');
    }
}
