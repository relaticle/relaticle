<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Support\Media\MediaPaths;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractInfolistEntry;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;

final class FileEntry extends AbstractInfolistEntry
{
    public function make(CustomField $customField): TextEntry
    {
        return TextEntry::make($customField->getFieldName())
            ->label($customField->name)
            ->state(fn (HasCustomFields&Model $record): ?string => $record->getCustomFieldValue($customField))
            ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : basename($state))
            ->url(fn (?string $state, Model $record): ?string => $state === null
                ? null
                : resolve(MediaPaths::class)->find((string) $record->getAttribute('team_id'), $state)?->getUrl())
            ->openUrlInNewTab();
    }
}
