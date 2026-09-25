<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Components\RecordChip;
use Illuminate\Database\Eloquent\Model;

trait HasRecordChips
{
    protected function recordChipLabel(Model $record, ?string $name = null): string
    {
        return RecordChip::forRecord($record, $name)->toHtml();
    }

    /**
     * `make('name')` chips the row's own record, `make('company.name')` the
     * related one, and `make('people.name')` every record in the relation.
     *
     * @param  Model|array<string, mixed>|null  $record
     * @return array<int, RecordChip>
     */
    protected function recordChips(Model|array|null $record, string $name, string $size = 'sm'): array
    {
        if (! $record instanceof Model) {
            return [];
        }

        $relationship = str($name)->beforeLast('.')->toString();

        if ($relationship === $name) {
            return RecordChip::forRecords($record, $size);
        }

        return RecordChip::forRecords(data_get($record, $relationship), $size);
    }
}
