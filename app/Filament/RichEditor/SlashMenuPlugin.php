<?php

declare(strict_types=1);

namespace App\Filament\RichEditor;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\Actions\AttachFilesAction;
use Filament\Forms\Components\RichEditor\Actions\LinkAction;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Icons\Heroicon;
use LogicException;
use Tiptap\Core\Extension;

use function Filament\Support\generate_icon_html;

/**
 * A `/` command menu for the rich editor, so block formatting needs no toolbar.
 *
 * Every entry is one of Filament's own `RichEditorTool`s. The menu reuses that tool's
 * icon and JS handler rather than restating them, which is what keeps a menu entry and
 * its toolbar equivalent from drifting apart.
 */
final class SlashMenuPlugin implements RichContentPlugin
{
    /**
     * Filament tool name => its menu entry. `group` buckets it under a heading,
     * `shortcut` shows the markdown that does the same thing (only ones verified to
     * fire: there is no code-fence rule), and `icon` overrides the tool's own, which
     * for the file upload is a paperclip while the entry reads "Image".
     *
     * @var array<string, array{group: string, shortcut?: string, icon?: Heroicon}>
     */
    private const array ITEMS = [
        'h1' => ['group' => 'text', 'shortcut' => '#'],
        'h2' => ['group' => 'text', 'shortcut' => '##'],
        'h3' => ['group' => 'text', 'shortcut' => '###'],
        'paragraph' => ['group' => 'text'],
        'blockquote' => ['group' => 'text', 'shortcut' => '>'],
        'bulletList' => ['group' => 'lists', 'shortcut' => '-'],
        'orderedList' => ['group' => 'lists', 'shortcut' => '1.'],
        'codeBlock' => ['group' => 'insert'],
        'table' => ['group' => 'insert'],
        'details' => ['group' => 'insert'],
        'horizontalRule' => ['group' => 'insert', 'shortcut' => '---'],
        'attachFiles' => ['group' => 'insert', 'icon' => Heroicon::OutlinedPhoto],
    ];

    public static function make(): self
    {
        return resolve(self::class);
    }

    /**
     * The menu is delivered as an attribute on the editor's wrapper rather than through
     * `getEditorTools()`, because a tool only renders when the toolbar lists it and this
     * editor has no toolbar.
     *
     * @return array<string, string>
     */
    public static function attributes(RichEditor $editor): array
    {
        $tools = $editor->getTools();

        $items = [];

        foreach (self::ITEMS as $name => $item) {
            $tool = $tools[$name] ?? throw new LogicException("Slash menu item [{$name}] is not a rich editor tool.");

            $items[] = [
                'id' => $name,
                'label' => __("filament/rich-editor.slash_menu.items.{$name}.label"),
                'description' => __("filament/rich-editor.slash_menu.items.{$name}.description"),
                'group' => __("filament/rich-editor.slash_menu.groups.{$item['group']}"),
                'shortcut' => $item['shortcut'] ?? null,
                'icon' => self::iconHtml($tool, $item['icon'] ?? null),
                'action' => $tool->getJsHandler(),
            ];
        }

        // Everything travels in one base64 attribute because Laravel's attribute bag
        // escapes a `"` as `\"`, which means nothing in HTML: the first quote closes the
        // attribute and the remainder is parsed as markup. A quote in a translated
        // string is enough to do it, and the debris lands on the editor's wrapper as a
        // stray `:query` attribute that Alpine then reads as an empty binding.
        // Encoding keeps default json_encode escaping, so the payload stays ASCII and
        // `atob` is safe.
        return [
            'data-slash-menu' => base64_encode((string) json_encode([
                'items' => $items,
                'noResults' => __('filament/rich-editor.slash_menu.no_results'),
                'placeholder' => __('filament/rich-editor.placeholder'),
            ])),
        ];
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [FilamentAsset::getScriptSrc('rich-editor-slash-menu')];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [];
    }

    /**
     * Filament's own actions, re-declared so they overlay the form they were opened
     * from instead of replacing it. `cacheActions()` keys by name and plugin actions
     * are merged last, so these win.
     *
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [
            AttachFilesAction::make()->overlayParentActions(),
            LinkAction::make()->overlayParentActions(),
        ];
    }

    private static function iconHtml(RichEditorTool $tool, ?Heroicon $icon): string
    {
        return (string) generate_icon_html(
            $icon ?? $tool->getIcon(),
            alias: $icon instanceof Heroicon ? null : $tool->getIconAlias(),
        )?->toHtml();
    }
}
