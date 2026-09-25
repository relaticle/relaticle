<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Relaticle\CustomFields\FieldTypeSystem\Definitions\LinkFieldType as BaseLinkFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

final class LinkFieldType extends BaseLinkFieldType
{
    public function configure(): FieldSchema
    {
        return parent::configure()->infolistEntry(LinkEntry::class);
    }
}
