<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support\EntityLinkStorage;

use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;

/**
 * Storage strategy for the custom fields that link records.
 *
 * Sets the custom field attribute on the model (custom_fields_xxx). The importer writes
 * it to the link ledger after the record is saved.
 */
final class CustomFieldValueStorage implements EntityLinkStorageInterface
{
    public function store(Model $record, EntityLink $link, array $resolvedIds, array $context): void
    {
        // Custom field values are set in prepareData(), no post-save action needed
    }

    public function prepareData(array $data, EntityLink $link, array $resolvedIds): array
    {
        if ($resolvedIds === [] || $link->customFieldCode === null) {
            return $data;
        }

        // A link field holds as many records as its cardinality allows, so a column that
        // resolved several keeps them all; a single-valued field keeps the first, which is
        // the one the row named first.
        $data['custom_fields_'.$link->customFieldCode] = $link->allowMultiple
            ? array_values($resolvedIds)
            : [$resolvedIds[0]];

        return $data;
    }
}
