<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Relaticle\CustomFields\FieldTypeSystem\Definitions\EmailFieldType as BaseEmailFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

final class EmailFieldType extends BaseEmailFieldType
{
    public function configure(): FieldSchema
    {
        return parent::configure()->infolistEntry(EmailEntry::class);
    }
}
