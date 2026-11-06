<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\RichEditor\ChecklistPlugin;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Filament\Forms\Components\RichEditor\RichContentRenderer;

final readonly class ChecklistHtml
{
    public const string ITEM = 'li[data-type="taskItem"]';

    public const string CHECKED_ITEM = 'li[data-type="taskItem"][data-checked="true"]';

    private const string MARKER = '/^\s*\[([ xX])\] /';

    private const string TASK_LIST_ATTRIBUTE = '/data-type\s*=\s*["\']?taskList/';

    private const string SKIPPED_ANCESTORS = 'pre, code, li[data-type="taskItem"]';

    private const array LIST_TAGS = ['ul', 'ol'];

    private const array BLOCK_TAGS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'div', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'figure', 'section', 'article', 'hr',
    ];

    public static function fromMarkdownHtml(string $html): string
    {
        if (preg_match('/\[[ xX]\] /', $html) !== 1) {
            return $html;
        }

        $document = self::parse($html);
        $converted = false;

        foreach ($document->querySelectorAll('ul') as $list) {
            if ($list->closest(self::SKIPPED_ANCESTORS) instanceof Element || ! self::startsChecklist($list)) {
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

    /** @return array{checked: int, total: int}|null */
    public static function progress(string $html): ?array
    {
        if (! str_contains($html, 'taskItem')) {
            return null;
        }

        $document = self::parse($html);
        $total = $document->querySelectorAll(self::ITEM)->length;

        if ($total === 0) {
            return null;
        }

        return [
            'checked' => $document->querySelectorAll(self::CHECKED_ITEM)->length,
            'total' => $total,
        ];
    }

    private static function parse(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');
    }

    private static function rebuild(HTMLDocument $document): string
    {
        foreach ($document->querySelectorAll('ul[data-type="taskList"]') as $list) {
            if ($list->closest(self::SKIPPED_ANCESTORS) instanceof Element) {
                continue;
            }

            $list->replaceChildren(...array_map(
                fn (Element $item): Element => self::bareItem($document, $item),
                self::collectItems($document, $list),
            ));

            $list->outerHTML = self::rendered($list->outerHTML);
        }

        return $document->body->innerHTML;
    }

    private static function startsChecklist(Element $list): bool
    {
        foreach ($list->children as $item) {
            if ($item->localName === 'li' && preg_match(self::MARKER, self::firstText($item)->data ?? '') === 1) {
                return true;
            }
        }

        return false;
    }

    private static function tagItems(HTMLDocument $document, Element $list): void
    {
        $items = self::collectItems($document, $list);

        $list->setAttribute('data-type', 'taskList');

        foreach ($items as $item) {
            $item->setAttribute('data-checked', self::consumeMarker($item) ? 'true' : 'false');
            $item->setAttribute('data-type', 'taskItem');
        }
    }

    private static function consumeMarker(Element $item): bool
    {
        $text = self::firstText($item);

        if (! $text instanceof Text || preg_match(self::MARKER, $text->data, $matches) !== 1) {
            return false;
        }

        $text->data = substr($text->data, strlen($matches[0]));

        return strtolower($matches[1]) === 'x';
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

    /** @return list<Element> */
    private static function collectItems(HTMLDocument $document, Element $list): array
    {
        $items = [];
        $stray = null;

        foreach (iterator_to_array($list->childNodes, false) as $child) {
            $isElement = $child instanceof Element;

            if ($isElement && $child->localName === 'li') {
                $stray = null;
                $items[] = $child;
                array_push($items, ...self::nestedItems($document, $child));

                continue;
            }

            if ($isElement && in_array($child->localName, self::LIST_TAGS, true)) {
                $stray = null;
                array_push($items, ...self::collectItems($document, $child));

                continue;
            }

            if (! $isElement && ! $child instanceof Text) {
                continue;
            }

            if (! $stray instanceof Element && $child instanceof Text && trim($child->data) === '') {
                continue;
            }

            if (! $stray instanceof Element) {
                $stray = $document->createElement('li');
                $items[] = $stray;
            }

            $stray->append($child);
        }

        return $items;
    }

    /** @return list<Element> */
    private static function nestedItems(HTMLDocument $document, Element $item): array
    {
        $items = [];

        foreach (self::contentNodes($item) as $node) {
            if ($node instanceof Element && in_array($node->localName, self::LIST_TAGS, true)) {
                array_push($items, ...self::collectItems($document, $node));
            }
        }

        return $items;
    }

    private static function bareItem(HTMLDocument $document, Element $source): Element
    {
        $item = $document->createElement('li');
        $item->setAttribute('data-checked', $source->getAttribute('data-checked') === 'true' ? 'true' : 'false');
        $item->setAttribute('data-type', 'taskItem');
        $item->append(...self::itemParagraphs($document, self::contentNodes($source)));

        return $item;
    }

    private static function rendered(string $taskList): string
    {
        return RichContentRenderer::make($taskList)
            ->plugins([ChecklistPlugin::make()])
            ->toUnsafeHtml();
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
            fn (Node $part): bool => ! $part instanceof Element || ($part->localName !== 'input' && ($part->localName !== 'span' || $part->hasChildNodes())),
        ));
    }

    /**
     * @param  list<Node>  $nodes
     * @return list<Element>
     */
    private static function itemParagraphs(HTMLDocument $document, array $nodes): array
    {
        $paragraphs = self::paragraphs($document, array_values(array_filter(
            $nodes,
            fn (Node $node): bool => ! $node instanceof Element || ! in_array($node->localName, self::LIST_TAGS, true),
        )));

        return $paragraphs === [] ? [$document->createElement('p')] : $paragraphs;
    }

    /**
     * @param  list<Node>  $nodes
     * @return list<Element>
     */
    private static function paragraphs(HTMLDocument $document, array $nodes): array
    {
        $paragraphs = [];
        $loose = [];
        $current = null;

        foreach ($nodes as $node) {
            $tag = $node instanceof Element ? $node->localName : null;

            if ($tag === 'p') {
                $paragraphs[] = $node;
                $current = null;

                continue;
            }

            if (in_array($tag, self::BLOCK_TAGS, true)) {
                array_push($paragraphs, ...self::paragraphs($document, iterator_to_array($node->childNodes, false)));
                $current = null;

                continue;
            }

            if (! $current instanceof Element) {
                if ($node instanceof Text && trim($node->data) === '') {
                    continue;
                }

                $current = $document->createElement('p');
                $paragraphs[] = $current;
                $loose[] = $current;
            }

            $current->append($node);
        }

        foreach ($loose as $paragraph) {
            self::trimEdges($paragraph);
        }

        return $paragraphs;
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
