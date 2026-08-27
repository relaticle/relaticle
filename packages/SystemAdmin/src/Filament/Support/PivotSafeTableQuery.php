<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Filament's BelongsToMany table query merges the pivot model's casts into the
 * related model (filamentphp/filament#2079). Jetstream's team_user pivot has an
 * auto-incrementing key, so its casts include `id => int` — and PHP casts a
 * ULID such as "01m09g…" to the integer 1. Every row in a membership relation
 * manager then hydrates with key 1, so record links point at /teams/1 and 404.
 * Re-merging the related model's own key cast after Filament's merge restores
 * the real key while keeping the pivot casts Filament needed.
 */
final readonly class PivotSafeTableQuery
{
    public static function apply(Builder $query, Relation|Builder|null $relationship): Builder
    {
        if (! $relationship instanceof BelongsToMany) {
            return $query;
        }

        $model = $query->getModel();

        return $query->withCasts([$model->getKeyName() => $model->getKeyType()]);
    }
}
