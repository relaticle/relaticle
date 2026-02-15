<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support\EntityLinkStorage;

use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;

/**
 * Storage strategy for foreign key relationships (belongsTo).
 *
 * Sets the foreign key column directly on the model (e.g., company_id).
 */
final class ForeignKeyStorage implements EntityLinkStorageInterface
{
    public function store(Model $record, EntityLink $link, array $resolvedIds, array $context): void
    {
        // Foreign keys are set in prepareData(), no post-save action needed
    }

    public function prepareData(array $data, EntityLink $link, array $resolvedIds): array
    {
        $foreignKey = $link->foreignKey ?? $link->key.'_id';

        // BelongsTo only supports single ID
        $id = $resolvedIds[0] ?? null;

        if ($id !== null) {
            $data[$foreignKey] = $id;
        }

        return $data;
    }
}
