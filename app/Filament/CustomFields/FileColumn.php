<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Support\Media\MediaPaths;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractTableColumn;
use Relaticle\CustomFields\Filament\Integration\Concerns\Tables\ConfiguresColumnLabel;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class FileColumn extends AbstractTableColumn
{
    use ConfiguresColumnLabel;

    public function make(CustomField $customField): TextColumn
    {
        $column = TextColumn::make($customField->getFieldName());

        $this->configureLabel($column, $customField);

        return $column
            ->getStateUsing(fn (HasCustomFields&Model $record): ?string => $this->label($this->resolveMedia($record, $customField)))
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
