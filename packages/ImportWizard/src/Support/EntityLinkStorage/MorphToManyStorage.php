<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support\EntityLinkStorage;

use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;

/**
 * Storage strategy for polymorphic many-to-many relationships.
 *
 * Syncs the morph-to-many relation on the model after save.
 */
final class MorphToManyStorage implements EntityLinkStorageInterface
{
    public function store(Model $record, EntityLink $link, array $resolvedIds, array $context): void
    {
        if ($resolvedIds === []) {
            return;
        }

        $relationName = $link->morphRelation ?? $link->key;

        if (! method_exists($record, $relationName)) {
            return;
        }

        // Sync without detaching - adds new links while keeping existing ones
        $record->{$relationName}()->syncWithoutDetaching($resolvedIds);
    }

    public function prepareData(array $data, EntityLink $link, array $resolvedIds): array
    {
        // MorphToMany relations are synced in store(), no data preparation needed
        return $data;
    }
}
