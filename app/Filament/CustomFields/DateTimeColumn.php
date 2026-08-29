<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Filament\Tables\Columns\Column as BaseColumn;
use Filament\Tables\Columns\TextColumn;
use Relaticle\CustomFields\CustomFields;
use Relaticle\CustomFields\Filament\Integration\Components\Tables\Columns\DateTimeColumn as BaseDateTimeColumn;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;

/**
 * The package column formats the value itself and hands Filament a finished string,
 * so nothing downstream can still convert it — a date-time custom field renders the
 * stored UTC wall clock to every viewer, while the DateTimePicker that wrote it and
 * the infolist entry that echoes it both convert. Same value, three surfaces, two
 * answers.
 *
 * Give Filament the Carbon instance instead and let its own dateTime() formatter run:
 * that is the path that reads FilamentTimezone, and it is what the package's own
 * infolist entry already does.
 *
 * Registered for the `date-time` field type only (see AppServiceProvider). Date-only
 * fields must keep the package column: a bare date has no time of day to shift, so
 * converting one would move it a day for every viewer west of UTC.
 *
 * The format is passed straight through, null included. Filament resolves null to the
 * table's own default, so a custom-field datetime reads the same as the `created_at`
 * beside it instead of being pinned to a literal this class chose. That also means each
 * panel's format applies without this class knowing which panels exist.
 */
final class DateTimeColumn extends BaseDateTimeColumn
{
    public function make(CustomField $customField): BaseColumn
    {
        $column = parent::make($customField);

        if (! $column instanceof TextColumn) {
            return $column;
        }

        return $column
            ->getStateUsing(fn (HasCustomFields $record): mixed => $record->getCustomFieldValue($customField))
            ->dateTime(CustomFields::dateTimeDisplayFormat());
    }
}
