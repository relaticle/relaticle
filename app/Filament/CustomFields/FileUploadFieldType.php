<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

final class FileUploadFieldType extends BaseFieldType
{
    public function configure(): FieldSchema
    {
        return FieldSchema::file()
            ->key('file-upload')
            ->label(__('custom-fields::custom-fields.field_types.file_upload'))
            ->icon('heroicon-o-paper-clip')
            ->formComponent(FileUploadComponent::class)
            ->tableColumn(FileColumn::class)
            ->infolistEntry(FileEntry::class)
            ->priority(17)
            ->searchable()
            ->defaultValidationRules([]);
    }
}
