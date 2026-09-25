<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Support\Media\RichContentAttachments;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractInfolistEntry;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;

final class RichContentEntry extends AbstractInfolistEntry
{
    public function make(CustomField $customField): TextEntry
    {
        return TextEntry::make($customField->getFieldName())
            ->html()
            ->label($customField->name)
            ->extraAttributes(['class' => 'fi-inline-rich-preview'])
            ->state(fn (HasCustomFields&Model $record): ?string => $this->render($record, $customField));
    }

    private function render(HasCustomFields&Model $record, CustomField $customField): ?string
    {
        $value = $record->getCustomFieldValue($customField);

        if (! is_string($value) || $value === '') {
            return null;
        }

        $hasMedia = str_contains($value, '<img') || str_contains($value, 'data-id=');

        if (! $hasMedia && trim(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8')) === '') {
            return null;
        }

        $attachments = RichContentAttachments::forWorkspace((string) $record->getAttribute('workspace_id'));

        return RichContentRenderer::make($attachments->rewriteAttachmentUrls($value))
            ->fileAttachmentProvider($attachments)
            ->toHtml();
    }
}
