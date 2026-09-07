<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CustomFieldOption;
use Illuminate\Support\Facades\Log;
use Relaticle\CustomFields\Enums\OptionCategory;

/**
 * The ids of one field's options in a category, for the raw aggregate queries
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

        return self::idsIn($customFieldId, [$category]);
    }

    /**
     * The options that end a record's journey, completed and cancelled alike: a task
     * nobody will do is no more open work than a finished one.
     *
     * A field with no completed option has not been categorised yet. The caller then
     * gets an empty list and keeps showing every record, because hiding a set we
     * cannot describe loses data in front of the user; the gap is logged once.
     *
     * @return list<string>
     */
    public static function terminalIds(int|string $teamId, string $fieldCode, ?string $customFieldId): array
    {
        if ($customFieldId === null) {
            return [];
        }

        if (self::ids($customFieldId, OptionCategory::Completed) === []) {
            self::warnUncategorised($teamId, $fieldCode);

            return [];
        }

        return self::idsIn($customFieldId, array_filter(
            OptionCategory::cases(),
            static fn (OptionCategory $category): bool => $category->isTerminal(),
        ));
    }

    /**
     * @param  array<int, OptionCategory>  $categories
     * @return list<string>
     */
    private static function idsIn(string $customFieldId, array $categories): array
    {
        $ids = CustomFieldOption::query()
            ->withoutGlobalScopes()
            ->where('custom_field_id', $customFieldId)
            ->whereIn('settings->category', array_map(
                static fn (OptionCategory $category): string => $category->value,
                $categories,
            ))
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
    }

    private static function warnUncategorised(int|string $teamId, string $fieldCode): void
    {
        $seen = self::class.":uncategorised:{$teamId}:{$fieldCode}";

        if (app()->bound($seen)) {
            return;
        }

        app()->instance($seen, true);

        Log::warning('Custom field has no completed option, so finished records cannot be told apart.', [
            'team_id' => $teamId,
            'field_code' => $fieldCode,
        ]);
    }
}
