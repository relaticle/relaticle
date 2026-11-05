<?php

declare(strict_types=1);

namespace App\Support;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

final readonly class ChecklistHtml
{
    private const string MARKER = '/^\s*\[([ xX])\] /';

    private const string TASK_LIST_ATTRIBUTE = '/data-type\s*=\s*["\']?taskList/';

    private const array LIST_TAGS = ['ul', 'ol'];

    private const array BLOCK_TAGS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'div', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'figure', 'section', 'article',
    ];

    public static function fromMarkdownHtml(string $html): string
    {
        if (preg_match('/\[[ xX]\] /', $html) !== 1) {
            return $html;
        }

        $document = self::parse($html);
        $converted = false;

        foreach ($document->querySelectorAll('ul') as $list) {
            if ($list->closest('pre, code, li[data-type="taskItem"]') instanceof Element || ! self::startsChecklist($list)) {
                continue;
            }

            self::tagItems($document, $list);
            $converted = true;
        }

        if (! $converted) {
            return $html;
        }

        return self::rebuild($document);
    }

    public static function normalize(string $html): string
    {
        if (preg_match(self::TASK_LIST_ATTRIBUTE, $html) !== 1) {
            return $html;
        }

        return self::rebuild(self::parse($html));
    }

    private static function parse(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');
    }

    private static function rebuild(HTMLDocument $document): string
    {
        foreach ($document->querySelectorAll('ul[data-type="taskList"]') as $list) {
            if ($list->closest('pre, code, li[data-type="taskItem"]') instanceof Element) {
                continue;
            }

            $items = [];
            self::collectItems($document, $list, $items);

            $list->replaceChildren(...array_map(
                fn (Element $item): Element => self::buildItem($document, $item),
                $items,
            ));
        }

        return $document->body->innerHTML;
    }

    private static function startsChecklist(Element $list): bool
    {
        foreach ($list->children as $item) {
            if ($item->localName === 'li' && self::marker($item) instanceof Text) {
                return true;
            }
        }

        return false;
    }

    private static function tagItems(HTMLDocument $document, Element $list): void
    {
        $items = [];
        self::collectItems($document, $list, $items);

        $list->setAttribute('data-type', 'taskList');

        foreach ($items as $item) {
            $item->setAttribute('data-checked', self::consumeMarker($item) ? 'true' : 'false');
            $item->setAttribute('data-type', 'taskItem');
        }
    }

    private static function consumeMarker(Element $item): bool
    {
        $text = self::marker($item);

        if (! $text instanceof Text || preg_match(self::MARKER, $text->data, $matches) !== 1) {
            return false;
        }

        $text->data = substr($text->data, strlen($matches[0]));

        return strtolower($matches[1]) === 'x';
    }

    private static function marker(Element $item): ?Text
    {
        $text = self::firstText($item);

        return $text instanceof Text && preg_match(self::MARKER, $text->data) === 1 ? $text : null;
    }

    private static function firstText(Node $node): ?Text
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof Text && trim($child->data) !== '') {
                return $child;
            }

            if ($child instanceof Element) {
                return $child->localName === 'p' ? self::firstText($child) : null;
            }
        }

        return null;
    }

    /** @param list<Element> $items */
    private static function collectItems(HTMLDocument $document, Element $list, array &$items): void
    {
        $stray = null;

        foreach (iterator_to_array($list->childNodes, false) as $child) {
            $isElement = $child instanceof Element;

            if ($isElement && $child->localName === 'li') {
                $stray = null;
                $items[] = $child;
                self::collectNestedLists($document, $child, $items);

                continue;
            }

            if ($isElement && in_array($child->localName, self::LIST_TAGS, true)) {
                $stray = null;
                self::collectItems($document, $child, $items);

                continue;
            }

            if (! $isElement && ! $child instanceof Text) {
                continue;
            }

            if ($stray === null && $child instanceof Text && trim($child->data) === '') {
                continue;
            }

            if ($stray === null) {
                $stray = $document->createElement('li');
                $items[] = $stray;
            }

            $stray->append($child);
        }
    }

    /** @param list<Element> $items */
    private static function collectNestedLists(HTMLDocument $document, Element $item, array &$items): void
    {
        foreach (self::contentNodes($item) as $node) {
            if ($node instanceof Element && in_array($node->localName, self::LIST_TAGS, true)) {
                self::collectItems($document, $node, $items);
            }
        }
    }

    private static function buildItem(HTMLDocument $document, Element $source): Element
    {
        $checked = $source->getAttribute('data-checked') === 'true';

        $input = $document->createElement('input');
        $input->setAttribute('type', 'checkbox');

        if ($checked) {
            $input->setAttribute('checked', 'checked');
        }

        $label = $document->createElement('label');
        $label->append($input, $document->createElement('span'));

        $body = $document->createElement('div');
        $body->append(...self::paragraphs($document, self::contentNodes($source)));

        $item = $document->createElement('li');
        $item->setAttribute('data-checked', $checked ? 'true' : 'false');
        $item->setAttribute('data-type', 'taskItem');
        $item->append($label, $body);

        return $item;
    }

    /** @return list<Node> */
    private static function contentNodes(Element $item): array
    {
        $nodes = [];

        foreach (iterator_to_array($item->childNodes, false) as $child) {
            if ($child instanceof Element && $child->localName === 'label' && $child->querySelector('input[type="checkbox"]') instanceof Element) {
                array_push($nodes, ...self::labelText($child));

                continue;
            }

            if ($child instanceof Element && $child->localName === 'div') {
                array_push($nodes, ...self::contentNodes($child));

                continue;
            }

            $nodes[] = $child;
        }

        return $nodes;
    }

    /** @return list<Node> */
    private static function labelText(Element $label): array
    {
        return array_values(array_filter(
            iterator_to_array($label->childNodes, false),
            fn (Node $part): bool => ! ($part instanceof Element && ($part->localName === 'input' || ($part->localName === 'span' && ! $part->hasChildNodes()))),
        ));
    }

    /**
     * @param  list<Node>  $nodes
     * @return list<Element>
     */
    private static function paragraphs(HTMLDocument $document, array $nodes, bool $liftLists = true): array
    {
        $paragraphs = [];
        $loose = [];
        $current = null;

        foreach ($nodes as $node) {
            $tag = $node instanceof Element ? $node->localName : null;

            if ($liftLists && in_array($tag, self::LIST_TAGS, true)) {
                continue;
            }

            if ($tag === 'p') {
                $paragraphs[] = $node;
                $current = null;

                continue;
            }

            if (in_array($tag, self::BLOCK_TAGS, true)) {
                array_push($paragraphs, ...self::paragraphs($document, iterator_to_array($node->childNodes, false), false));
                $current = null;

                continue;
            }

            if ($current === null) {
                if ($node instanceof Text && trim($node->data) === '') {
                    continue;
                }

                $current = $document->createElement('p');
                $paragraphs[] = $current;
                $loose[] = $current;
            }

            $current->append($node);
        }

        array_map(self::trimEdges(...), $loose);

        return $paragraphs === [] && $liftLists ? [$document->createElement('p')] : $paragraphs;
    }

    private static function trimEdges(Element $paragraph): void
    {
        $paragraph->normalize();

        $first = $paragraph->firstChild;
        $last = $paragraph->lastChild;

        if ($first instanceof Text) {
            $first->data = ltrim($first->data);
        }

        if ($last instanceof Text) {
            $last->data = rtrim($last->data);
        }
    }
}
