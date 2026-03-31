<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support\EntityLinkStorage;

use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;

/**
 * Storage strategy for Record-type custom field values.
 *
 * Sets the custom field value attribute on the model (custom_fields_xxx).
 * The model's custom fields handling will persist to custom_field_values table.
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

        $attributeKey = 'custom_fields_'.$link->customFieldCode;

        // Record custom fields support single ID
        $id = $resolvedIds[0] ?? null;

        if ($id !== null) {
            $data[$attributeKey] = $id;
        }

        return $data;
    }
}
