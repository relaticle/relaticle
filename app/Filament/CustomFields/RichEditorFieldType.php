<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Enums\CrmEntity;
use App\Enums\CustomFields\NoteField;
use App\Enums\CustomFields\TaskField;
use App\Filament\RichEditor\SlashMenuPlugin;
use Filament\Forms\Components\RichEditor;
use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;
use Relaticle\CustomFields\FieldTypeSystem\Definitions\RichEditorFieldType as PackageRichEditorFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;
use Relaticle\CustomFields\Models\CustomField;

final class RichEditorFieldType extends BaseFieldType
{
    // Never add `heading`: Filament shows a node's floating toolbar whenever the caret
    // sits in it, which would park a toolbar under every heading being typed.
    /** @var list<string> */
    private const array FLOATING_TOOLBAR = ['bold', 'italic', 'underline', 'strike', 'code', 'highlight', 'link'];

    // The borderless canvas fills the form's slack, which only reads as a document where
    // the editor is the form's main field. Elsewhere it would swallow the fields around it.
    /** @var array<string, string> */
    private const array DOCUMENT_FIELDS = [
        CrmEntity::Note->value => NoteField::BODY->value,
        CrmEntity::Task->value => TaskField::DESCRIPTION->value,
    ];

    public function configure(): FieldSchema
    {
        return (new PackageRichEditorFieldType)->configure()
            ->formComponent(fn (CustomField $customField): RichEditor => RichEditor::make($customField->getFieldName())
                ->plugins([SlashMenuPlugin::make()])
                ->toolbarButtons([])
                // The defaults carry the table controls, unreachable otherwise without a toolbar.
                ->floatingToolbars(fn (RichEditor $component): array => [
                    'paragraph' => self::FLOATING_TOOLBAR,
                    ...$component->getDefaultFloatingToolbars(),
                ])
                ->extraAttributes(fn (RichEditor $component): array => [
                    ...SlashMenuPlugin::attributes($component),
                    ...(self::isDocument($customField) ? ['class' => 'fi-fo-rich-editor-seamless'] : []),
                ]));
    }

    private static function isDocument(CustomField $customField): bool
    {
        return (self::DOCUMENT_FIELDS[$customField->entity_type] ?? null) === $customField->code;
    }
}
