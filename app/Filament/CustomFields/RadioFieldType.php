<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Relaticle\CustomFields\FieldTypeSystem\Definitions\RadioFieldType as BaseRadioFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

final class RadioFieldType extends BaseRadioFieldType
{
    public function configure(): FieldSchema
    {
        return parent::configure()->infolistEntry(OptionChipEntry::class);
    }
}
