<?php

declare(strict_types=1);

namespace App\Data\Import;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Analysis results for a single CSV column including unique values and validation issues.
 */
final class ColumnAnalysis extends Data
{
    /**
     * @param  array<string, int>  $uniqueValues  Map of value to occurrence count
     * @param  DataCollection<int, ValueIssue>  $issues
     */
    public function __construct(
        public string $csvColumnName,
        public string $mappedToField,
        public string $fieldType,
        public int $totalValues,
        public int $uniqueCount,
        public int $blankCount,
        public array $uniqueValues,
        #[DataCollectionOf(ValueIssue::class)]
        public DataCollection $issues,
        public bool $isRequired = false,
    ) {}

    /**
     * Get unique values for display with "load more" pattern.
     * Returns first (page * perPage) items cumulatively.
     *
     * @return array<string, int>
     */
    public function paginatedValues(int $page = 1, int $perPage = 100, ?string $search = null): array
    {
        $values = $this->uniqueValues;

        if ($search !== null && $search !== '') {
            $values = array_filter(
                $values,
                fn (int $count, string $value): bool => str_contains(
                    strtolower($value),
                    strtolower($search)
                ),
                ARRAY_FILTER_USE_BOTH
            );
        }

        // Cumulative: show all items up to page * perPage
        $limit = $page * $perPage;

        return array_slice($values, 0, $limit, preserve_keys: true);
    }
}
