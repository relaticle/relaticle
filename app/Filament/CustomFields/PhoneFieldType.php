<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Relaticle\CustomFields\FieldTypeSystem\Definitions\PhoneFieldType as BasePhoneFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

final class PhoneFieldType extends BasePhoneFieldType
{
    public function configure(): FieldSchema
    {
        return parent::configure()->infolistEntry(PhoneEntry::class);
    }
}
