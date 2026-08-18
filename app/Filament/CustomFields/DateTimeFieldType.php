<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Relaticle\CustomFields\FieldTypeSystem\Definitions\DateTimeFieldType as BaseDateTimeFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

/**
 * Swaps in the timezone-aware table column and the format-consistent infolist entry, and
 * changes nothing else. Registering this under the same `date-time` key replaces the
 * package definition, because FieldManager keys its collection by field-type key and the
 * last registration wins.
 *
 * Both surfaces are overridden together on purpose: a table and the record page it links
 * to showing the same value two ways is the bug, so fixing one without the other only
 * moves it.
 */
final class DateTimeFieldType extends BaseDateTimeFieldType
{
    public function configure(): FieldSchema
    {
        return parent::configure()
            ->tableColumn(DateTimeColumn::class)
            ->infolistEntry(DateTimeEntry::class);
    }
}
