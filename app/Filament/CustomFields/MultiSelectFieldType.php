<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Relaticle\CustomFields\FieldTypeSystem\Definitions\MultiSelectFieldType as BaseMultiSelectFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

final class MultiSelectFieldType extends BaseMultiSelectFieldType
{
    public function configure(): FieldSchema
    {
        return parent::configure()->infolistEntry(OptionChipEntry::class);
    }
}
