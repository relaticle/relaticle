<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\CustomFieldValue;

/**
 * Merges partial custom_fields with existing values before update.
 *
 * The custom-fields package's saveCustomFields() iterates ALL defined fields
 * and writes null for any not present in the submitted array. This helper
 * loads the model's current values and merges submitted fields on top,
 * so omitted fields are preserved.
 */
final readonly class CustomFieldMerger
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function merge(Model $model, array $attributes): array
    {
        if (! isset($attributes['custom_fields']) || ! is_array($attributes['custom_fields'])) {
            return $attributes;
        }

        if (! method_exists($model, 'customFieldValues')) {
            return $attributes;
        }

        $model->loadMissing('customFieldValues.customField');

        /** @var \Illuminate\Database\Eloquent\Collection<int, CustomFieldValue> $values */
        $values = $model->getRelation('customFieldValues');

        $existing = $values
            /** @phpstan-ignore notIdentical.alwaysTrue (orphaned values can exist when a custom field is deleted) */
            ->filter(fn (CustomFieldValue $v): bool => $v->customField !== null)
            ->mapWithKeys(fn (CustomFieldValue $v): array => [
                $v->customField->code => self::normalizeValue($v->getValue()),
            ])
            ->all();

        $attributes['custom_fields'] = array_merge($existing, $attributes['custom_fields']);

        return $attributes;
    }

    /**
     * Normalize getValue() output to the input format saveCustomFields() expects.
     *
     * getValue() returns Collection for multi-choice fields (link, email, phone),
     * but saveCustomFields() expects plain arrays.
     */
    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            return $value->all();
        }

        return $value;
    }
}
