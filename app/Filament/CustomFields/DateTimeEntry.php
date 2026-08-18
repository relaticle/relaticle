<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Filament\Infolists\Components\TextEntry;
use Relaticle\CustomFields\CustomFields;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractInfolistEntry;
use Relaticle\CustomFields\Models\CustomField;

/**
 * The package entry falls back to a literal `Y-m-d H:i:s` when the package has no display
 * format configured, which it never does here — so a record's view page printed
 * `2026-08-19 08:30:00` for the same field the table beside it rendered as
 * `Aug 19, 2026 08:30`.
 *
 * Pass the format through untouched, null included, so Filament resolves it to the panel's
 * own default. Same fix already applied to the table column in {@see DateTimeColumn}: the
 * two surfaces have to agree, and neither should hardcode a literal the panel did not pick.
 *
 * Written out rather than subclassed because the package entry is final.
 */
final class DateTimeEntry extends AbstractInfolistEntry
{
    public function make(CustomField $customField): TextEntry
    {
        $isDateTime = $customField->isDateTimeField();

        $format = $isDateTime
            ? CustomFields::dateTimeDisplayFormat()
            : CustomFields::dateDisplayFormat();

        $entry = TextEntry::make($customField->getFieldName())
            ->label($customField->name)
            ->state(fn (mixed $record): mixed => $record->getCustomFieldValue($customField));

        return $isDateTime
            ? $entry->dateTime($format)
            : $entry->date($format);
    }
}
