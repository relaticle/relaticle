<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Relaticle\CustomFields\FieldTypeSystem\Definitions\DateTimeFieldType as BaseDateTimeFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

/**
 * Swaps in the timezone-aware table column and changes nothing else. Registering this
 * under the same `date-time` key replaces the package definition, because FieldManager
 * keys its collection by field-type key and the last registration wins.
 */
final class DateTimeFieldType extends BaseDateTimeFieldType
{
    public function configure(): FieldSchema
    {
        return parent::configure()->tableColumn(DateTimeColumn::class);
    }
}
