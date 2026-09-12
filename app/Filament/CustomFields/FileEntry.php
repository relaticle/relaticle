<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Support\Media\MediaPaths;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractInfolistEntry;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class FileEntry extends AbstractInfolistEntry
{
    public function make(CustomField $customField): TextEntry
    {
        return TextEntry::make($customField->getFieldName())
            ->label($customField->name)
            ->state(fn (HasCustomFields&Model $record): ?string => $this->label($this->resolveMedia($record, $customField)))
            ->url(fn (HasCustomFields&Model $record): ?string => $this->resolveMedia($record, $customField)?->getUrl())
            ->openUrlInNewTab();
    }

    private function resolveMedia(HasCustomFields&Model $record, CustomField $customField): ?Media
    {
        $value = $record->getCustomFieldValue($customField);

        if (! is_string($value)) {
            return null;
        }

        return resolve(MediaPaths::class)->find((string) $record->getAttribute('team_id'), $value);
    }

    private function label(?Media $media): ?string
    {
        return $media?->getCustomProperty('original_name', $media->file_name);
    }
}
