<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Filament\RichEditor\ChecklistPlugin;
use App\Filament\RichEditor\SlashMenuPlugin;
use App\Models\CustomField as AppCustomField;
use App\Support\Media\RichContentAttachments;
use App\Support\Media\UploadAllowlist;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\ToolbarButtonGroup;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;
use Relaticle\CustomFields\FieldTypeSystem\Definitions\RichEditorFieldType as PackageRichEditorFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;
use Relaticle\CustomFields\Models\CustomField;

final class RichEditorFieldType extends BaseFieldType
{
    /** @var list<string> */
    private const array FLOATING_TOOLBAR = ['bold', 'italic', 'underline', 'strike', 'code', 'highlight', 'link'];

    public function configure(): FieldSchema
    {
        return (new PackageRichEditorFieldType)->configure()
            ->formComponent(fn (CustomField $customField): RichEditor => RichEditorComponent::make($customField->getFieldName())
                ->plugins($this->plugins($customField))
                ->toolbarButtons([])
                // Filament decides attachments by whether `attachFiles` sits in the toolbar,
                // and there is no toolbar: without this, an uploaded image saves as null.
                ->fileAttachments(true)
                ->fileAttachmentsVisibility('private')
                ->fileAttachmentsMaxSize((int) (UploadAllowlist::maxBytes() / 1024))
                ->dehydrateStateUsing(fn (mixed $state): mixed => is_string($state)
                    ? $this->attachments()->canonicalize($state)
                    : $state)
                ->saveUploadedFileAttachmentUsing(fn (TemporaryUploadedFile $file): string => $this->attachments()->saveUploadedFileAttachment($file))
                ->getFileAttachmentUrlUsing(fn (mixed $file): ?string => $this->attachments()->getFileAttachmentUrl($file))
                // The defaults carry the table controls, unreachable otherwise without a toolbar.
                ->floatingToolbars(function (RichEditor $component): array {
                    $tools = $component->getTools();
                    $tools['paragraph']->label(__('filament/rich-editor.slash_menu.items.paragraph.label'));
                    $tools['h1']->label(__('filament/rich-editor.slash_menu.items.h1.label'));
                    $tools['h2']->label(__('filament/rich-editor.slash_menu.items.h2.label'));
                    $tools['h3']->label(__('filament/rich-editor.slash_menu.items.h3.label'));

                    return [
                        'paragraph' => $this->selectionToolbar(),
                        'heading' => $this->selectionToolbar(),
                        ...$component->getDefaultFloatingToolbars(),
                    ];
                })
                ->extraAttributes(fn (RichEditor $component): array => [
                    ...SlashMenuPlugin::attributes($component),
                    ...($customField instanceof AppCustomField && $customField->isDocument() ? ['class' => 'fi-fo-rich-editor-seamless'] : []),
                ]))
            ->infolistEntry(RichContentEntry::class);
    }

    /** @return list<RichContentPlugin> */
    private function plugins(CustomField $customField): array
    {
        $plugins = [SlashMenuPlugin::make()];

        if ($customField instanceof AppCustomField && $customField->holdsChecklists()) {
            $plugins[] = ChecklistPlugin::make();
        }

        return $plugins;
    }

    private function attachments(): RichContentAttachments
    {
        return RichContentAttachments::forWorkspace((string) Filament::getTenant()?->getKey());
    }

    /** @return list<string | ToolbarButtonGroup> */
    private function selectionToolbar(): array
    {
        return [
            ToolbarButtonGroup::make(
                __('filament/rich-editor.selection_toolbar.text_style'),
                ['paragraph', 'h1', 'h2', 'h3'],
            )->textualButtons(),
            ...self::FLOATING_TOOLBAR,
        ];
    }
}
