<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Filament\RichEditor\SlashMenuPlugin;
use Filament\Forms\Components\RichEditor;
use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;
use Relaticle\CustomFields\FieldTypeSystem\Definitions\RichEditorFieldType as PackageRichEditorFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;
use Relaticle\CustomFields\Models\CustomField;

/**
 * Gives every rich-editor custom field a document-style editor: no toolbar, blocks from
 * the `/` menu, inline marks from a toolbar that floats over the selection.
 *
 * The package class is final, so the schema is taken from an instance of it rather than
 * inherited, and only the form component is replaced.
 */
final class RichEditorFieldType extends BaseFieldType
{
    /**
     * Shown over a selection inside a paragraph, which covers list items too, since a
     * list item wraps one. Headings are deliberately absent: Filament shows a node's
     * floating toolbar whenever the cursor sits in that node, so registering `heading`
     * would park a toolbar under the caret for as long as you type a heading.
     *
     * @var list<string>
     */
    private const array FLOATING_TOOLBAR = ['bold', 'italic', 'underline', 'strike', 'code', 'highlight', 'link'];

    public function configure(): FieldSchema
    {
        return (new PackageRichEditorFieldType)->configure()
            ->formComponent(fn (CustomField $customField): RichEditor => RichEditor::make($customField->getFieldName())
                ->plugins([SlashMenuPlugin::make()])
                ->toolbarButtons([])
                // Filament's default covers tables, whose controls would otherwise be
                // unreachable in existing content now that there is no toolbar.
                ->floatingToolbars(fn (RichEditor $component): array => [
                    'paragraph' => self::FLOATING_TOOLBAR,
                    ...$component->getDefaultFloatingToolbars(),
                ])
                ->extraAttributes(fn (RichEditor $component): array => [
                    ...SlashMenuPlugin::attributes($component),
                    'class' => 'fi-fo-rich-editor-seamless',
                ]));
    }
}
