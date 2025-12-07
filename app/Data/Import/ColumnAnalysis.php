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

    public function hasIssues(): bool
    {
        return $this->issues->count() > 0;
    }

    public function issueCount(): int
    {
        return $this->issues->count();
    }

    /**
     * Get all issue values as an array.
     *
     * @return array<string>
     */
    public function issueValues(): array
    {
        return $this->issues->toCollection()->pluck('value')->all();
    }

    /**
     * Get paginated unique values for display.
     *
     * @return array<string, int>
     */
    public function paginatedValues(int $page = 1, int $perPage = 50, ?string $search = null): array
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

        $offset = ($page - 1) * $perPage;

        return array_slice($values, $offset, $perPage, preserve_keys: true);
    }

    public function totalPages(int $perPage = 50, ?string $search = null): int
    {
        $count = $search !== null && $search !== ''
            ? count(array_filter(
                $this->uniqueValues,
                fn (int $c, string $v): bool => str_contains(strtolower($v), strtolower($search)),
                ARRAY_FILTER_USE_BOTH
            ))
            : $this->uniqueCount;

        return (int) ceil($count / $perPage);
    }
}
