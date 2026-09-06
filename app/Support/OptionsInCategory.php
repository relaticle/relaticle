<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CustomFieldOption;
use Relaticle\CustomFields\Enums\OptionCategory;

/**
 * The ids of one field's options in one category, for the raw aggregate queries
 * that count and filter records by a stored option id.
 */
final readonly class OptionsInCategory
{
    /** @return list<string> */
    public static function ids(?string $customFieldId, OptionCategory $category): array
    {
        if ($customFieldId === null) {
            return [];
        }

        $ids = CustomFieldOption::query()
            ->withoutGlobalScopes()
            ->where('custom_field_id', $customFieldId)
            ->whereCategory($category)
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
    }
}
