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

final class FileColumn extends AbstractTableColumn
{
    use ConfiguresColumnLabel;

    public function make(CustomField $customField): TextColumn
    {
        $column = TextColumn::make($customField->getFieldName());

        $this->configureLabel($column, $customField);

        return $column
            ->getStateUsing(fn (HasCustomFields&Model $record): ?string => $record->getCustomFieldValue($customField))
            ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : basename($state))
            ->url(fn (?string $state, Model $record): ?string => $state === null
                ? null
                : resolve(MediaPaths::class)->find((string) $record->getAttribute('team_id'), $state)?->getUrl())
            ->openUrlInNewTab();
    }
}
