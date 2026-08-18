<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Relaticle\CustomFields\FieldTypeSystem\Definitions\DateFieldType as BaseDateFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

/**
 * Date-only fields share the package's infolist entry with date-time fields, so they
 * inherited the same hardcoded fallback and printed `2026-08-19` on a record page where
 * the table showed `Aug 19, 2026`. Swap in the format-consistent entry.
 *
 * Only the entry is replaced. The package's table column is deliberately kept: a bare
 * date has no time of day, so the timezone conversion {@see DateTimeColumn} performs
 * would move it a day for every viewer west of UTC.
 */
final class DateFieldType extends BaseDateFieldType
{
    public function configure(): FieldSchema
    {
        return parent::configure()->infolistEntry(DateTimeEntry::class);
    }
}
