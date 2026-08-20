<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Renders a `[Label](/r/{type}/{id})` citation as a record chip.
 *
 * The markup built below is the canonical chip. `applyRecordChips()` in
 * packages/Chat/resources/js/chat.js reproduces it character for character, so
 * a reply reads the same the moment it finishes streaming (client pipeline) as
 * it does after a reload (this pipeline). Changing the markup, the icon set or
 * the accepted URL shape here means changing it there in the same commit;
 * tests/Feature/Chat/ChipRenderingTest.php and the chip cases in
 * tests/Browser/Chat/TranscriptShapeTest.php hold the two sides together.
 */
final readonly class RecordChipRenderer implements NodeRendererInterface
{
    /**
     * The `/r/` URLs that become chips: a known record type plus an id made
     * only of characters that need no escaping in an attribute. Anything else
     * stays an ordinary link rather than growing an escaping rule the client
     * sweep would have to reproduce exactly.
     */
    private const string URL_PATTERN = '#^/r/([a-z_]+)/[\w-]+$#';

    /**
     * Heroicon outline path data keyed by the `/r/{type}/` URL segment. The key
     * is that segment, never the model name: a person is `people` here.
     *
     * @var array<string, string>
     */
    private const array ICONS = [
        'company' => 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
        'people' => 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
        'opportunity' => 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'task' => 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75',
        'note' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
    ];

    /**
     * Returning null hands the node to the next renderer for its class, which
     * is CommonMark's own LinkRenderer (this one is registered above it).
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        if (! $node instanceof Link) {
            return null;
        }

        if (preg_match(self::URL_PATTERN, $node->getUrl(), $matches) !== 1) {
            return null;
        }

        $type = $matches[1];
        $icon = self::ICONS[$type] ?? null;

        if ($icon === null) {
            return null;
        }

        // The label arrives pre-escaped: `html_input: strip` has already run,
        // and every inline renderer in the chain escapes its own literals.
        return sprintf(
            '<a class="chat-chip" data-record-type="%s" href="%s">'
            .'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">'
            .'<path stroke-linecap="round" stroke-linejoin="round" d="%s"></path>'
            .'</svg>'
            .'<span class="chat-chip-label">%s</span>'
            .'</a>',
            $type,
            $node->getUrl(),
            $icon,
            $childRenderer->renderNodes($node->children()),
        );
    }
}
