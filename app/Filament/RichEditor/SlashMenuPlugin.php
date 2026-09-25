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

final class SlashMenuPlugin implements RichContentPlugin
{
    // List only shortcuts verified to fire: there is no code-fence input rule.
    /** @var array<string, array{group: string, shortcut?: string, icon?: Heroicon}> */
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

    // Not `getEditorTools()`: a tool renders only when the toolbar lists it, and there is none.
    /** @return array<string, string> */
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

        // Base64, not raw JSON: the attribute bag escapes `"` as `\"`, so a quote in a
        // translation would close the attribute. Default json_encode keeps it ASCII for `atob`.
        return [
            'data-slash-menu' => base64_encode((string) json_encode([
                'items' => $items,
                'noResults' => __('filament/rich-editor.slash_menu.no_results'),
                'placeholder' => __('filament/rich-editor.placeholder'),
                'limitReached' => __('filament/rich-editor.limit_reached'),
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

    // Same names as Filament's own so they overlay the parent form: plugin actions merge last and win.
    /** @return array<Action> */
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
