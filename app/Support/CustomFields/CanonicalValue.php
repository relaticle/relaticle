<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;
use Relaticle\CustomFields\Models\CustomField;

final readonly class CanonicalValue
{
    public static function of(CustomField $field, string $value): string
    {
        $type = CustomFieldsType::getFieldTypeInstance($field->type);

        return $type instanceof BaseFieldType ? $type->normalize($value, $field) : $value;
    }

    /**
     * @return array<int, string>
     */
    public static function each(CustomField $field, mixed $value): array
    {
        return collect(is_iterable($value) ? $value : [$value])
            ->filter(fn (mixed $item): bool => filled($item))
            ->map(fn (mixed $item): string => self::of($field, (string) $item))
            ->reject(fn (string $item): bool => $item === '')
            ->unique(strict: true)
            ->values()
            ->all();
    }
}
