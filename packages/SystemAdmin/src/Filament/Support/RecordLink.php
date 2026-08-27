<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use Closure;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Builds deep links from a relation column or entry to the related record's own
 * resource, so every relation shown in the panel is one click away from the
 * record behind it.
 */
final readonly class RecordLink
{
    /**
     * Prefers the loaded relation over the foreign key, so a dangling reference
     * (a `creator_id` whose user was deleted) renders as plain text instead of a
     * link to a missing record. Falls back to the foreign key when the relation
     * was not eager-loaded, which keeps this free of lazy-loading violations.
     *
     * @param  class-string  $resource  A Filament resource class
     */
    public static function to(string $resource, string $relationship): Closure
    {
        return static function (Model $record) use ($resource, $relationship): ?string {
            if ($record->relationLoaded($relationship)) {
                $related = $record->getRelation($relationship);

                return $related instanceof Model
                    ? $resource::getUrl('view', ['record' => $related->getKey()])
                    : null;
            }

            $relation = $record->{$relationship}();
            $key = $relation instanceof BelongsTo
                ? $record->getAttribute($relation->getForeignKeyName())
                : null;

            return blank($key) ? null : $resource::getUrl('view', ['record' => $key]);
        };
    }

    /**
     * Morph aliases are used as keys, matching what is stored in the type column.
     * An unmapped alias yields no link rather than an error.
     *
     * @param  array<string, class-string>  $resources  Morph alias => Filament resource class
     */
    public static function toMorph(array $resources, string $typeColumn, string $keyColumn): Closure
    {
        return static function (Model $record) use ($resources, $typeColumn, $keyColumn): ?string {
            $type = $record->getAttribute($typeColumn);
            $key = $record->getAttribute($keyColumn);

            if (! is_string($type) || blank($key)) {
                return null;
            }

            $resource = $resources[$type] ?? null;

            return $resource === null ? null : $resource::getUrl('view', ['record' => $key]);
        };
    }
}
