<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Models\CustomField;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;

final readonly class CanonicalValue
{
    private const string PHONE_PATTERN = '/^\+\d{1,15}(;ext=\d+)?$/';

    public static function isPhone(CustomField $field, string $value): bool
    {
        return preg_match(self::PHONE_PATTERN, self::of($field, $value)) === 1;
    }

    public static function of(CustomField $field, string $value): string
    {
        $type = CustomFieldsType::getFieldTypeInstance($field->type);

        return $type instanceof BaseFieldType ? $type->normalize($value, $field) : $value;
    }

    /**
     * @return list<string>
     */
    public static function spellings(CustomField $field, string $value): array
    {
        $value = trim($value);

        $type = CustomFieldsType::getFieldTypeInstance($field->type);
        $equivalents = $type instanceof BaseFieldType ? $type->equivalentValues($value, $field) : [];

        return array_values(array_unique([self::of($field, $value), ...$equivalents, $value]));
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
