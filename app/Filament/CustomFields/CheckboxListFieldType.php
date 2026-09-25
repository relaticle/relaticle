<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Relaticle\CustomFields\FieldTypeSystem\Definitions\CheckboxListFieldType as BaseCheckboxListFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

final class CheckboxListFieldType extends BaseCheckboxListFieldType
{
    public function configure(): FieldSchema
    {
        return parent::configure()->infolistEntry(OptionChipEntry::class);
    }
}
