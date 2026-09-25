<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\CrmEntity;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Renders a `[Label](/r/{type}/{id})` citation as a record chip.
 *
 * The icon set is App\Enums\CrmEntity, read through its `iconPath()`. The markup
 * built below is the canonical chip. `applyRecordChips()` in
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
     * The glyph for a record type, for the surfaces that show a record outside a
     * chip, the proposal card's entity identity, above all. Same path data, so a
     * company looks like a company wherever chat draws one.
     */
    public static function iconPath(string $type): ?string
    {
        return CrmEntity::tryFrom($type)?->iconPath();
    }

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
        $icon = self::iconPath($type);

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
            $this->flattenLabel($childRenderer->renderNodes($node->children())),
        );
    }

    /**
     * A chip is a single-line pill, so a line break inside the label would split
     * it in two. The pipelines also spell a break differently (`<br />` plus a
     * newline here, `<br>` from marked on the client), so both collapse to one
     * space. Mirrors flattenChipLabel() in chat.js.
     */
    private function flattenLabel(string $label): string
    {
        $withoutBreaks = preg_replace('#<br\s*/?>#', "\n", $label) ?? $label;

        return preg_replace('#[\r\n]+#', ' ', $withoutBreaks) ?? $withoutBreaks;
    }
}
