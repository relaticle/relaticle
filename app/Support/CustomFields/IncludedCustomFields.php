<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Support\Media\MediaLookup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;

final readonly class IncludedCustomFields
{
    /**
     * @param  Model|Collection<int, Model>  $records
     * @param  list<string>  $relations
     */
    public static function load(Model|Collection $records, array $relations): void
    {
        $records = $records instanceof Model ? new Collection([$records]) : $records;
        $record = $records->first();

        if (! $record instanceof Model) {
            return;
        }

        $relations = array_values(array_filter(
            $relations,
            static fn (string $relation): bool => $record->relationLoaded($relation)
                && $record->isRelation($relation)
                && $record->{$relation}()->getRelated() instanceof HasCustomFields,
        ));

        if ($relations === []) {
            return;
        }

        $records->loadMissing(array_map(
            static fn (string $relation): string => "{$relation}.customFieldValues.customField.options",
            $relations,
        ));

        $included = $records->toBase()
            ->flatMap(static fn (Model $record): array => array_map($record->getRelation(...), $relations))
            ->flatten()
            ->filter();

        resolve(RecordNameResolver::class)->prime($included);
        resolve(MediaLookup::class)->prime($included);
    }
}
