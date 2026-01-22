<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\ImportWizard\Store\ImportStore;

/**
 * Analyzes column values from SQLite storage.
 */
final readonly class ColumnAnalyzer
{
    public function __construct(
        private ImportStore $store,
    ) {}

    /**
     * Get statistics for a column (unique count and blank count).
     *
     * @return array{uniqueCount: int, blankCount: int}
     */
    public function getColumnStats(string $csvColumn): array
    {
        $jsonPath = $this->jsonPath($csvColumn);

        $result = $this->store->connection()
            ->table('import_rows')
            ->selectRaw(
                "COUNT(DISTINCT COALESCE(json_extract(corrections, ?), json_extract(raw_data, ?), '')) as unique_count,
                 SUM(CASE WHEN COALESCE(json_extract(corrections, ?), json_extract(raw_data, ?), '') = '' THEN 1 ELSE 0 END) as blank_count",
                [$jsonPath, $jsonPath, $jsonPath, $jsonPath]
            )
            ->first();

        return [
            'uniqueCount' => (int) ($result->unique_count ?? 0),
            'blankCount' => (int) ($result->blank_count ?? 0),
        ];
    }

    /**
     * Get paginated unique values with offset support, search, filtering, and sorting.
     *
     * @return array{values: Collection<int, array<string, mixed>>, hasMore: bool, totalFiltered: int}
     */
    public function getUniqueValuesPaginated(
        string $csvColumn,
        int $page = 1,
        int $perPage = 100,
        string $search = '',
        string $filter = 'all',
        string $sortField = 'count',
        string $sortDirection = 'desc',
    ): array {
        $jsonPath = $this->jsonPath($csvColumn);
        $offset = ($page - 1) * $perPage;

        $baseQuery = $this->store->connection()
            ->table('import_rows')
            ->selectRaw(
                "COALESCE(json_extract(raw_data, ?), '') as raw_value, json_extract(corrections, ?) as correction, COUNT(*) as count",
                [$jsonPath, $jsonPath]
            )
            ->groupBy('raw_value', 'correction');

        $baseQuery->when($search !== '', fn ($q) => $q->havingRaw(
            "raw_value LIKE ? ESCAPE '\\'",
            [$this->escapeLikePattern($search)]
        ));

        $baseQuery->when($filter === 'modified', fn ($q) => $q->havingRaw("correction IS NOT NULL AND correction != ''"));
        $baseQuery->when($filter === 'skipped', fn ($q) => $q->havingRaw("correction = ''"));

        $totalFiltered = (int) $this->store->connection()
            ->query()
            ->fromSub($baseQuery, 'grouped')
            ->selectRaw('COUNT(*) as total')
            ->value('total');

        $results = $baseQuery
            ->orderBy($sortField, $sortDirection)
            ->offset($offset)
            ->limit($perPage + 1)
            ->get();

        $hasMore = $results->count() > $perPage;

        /** @var Collection<int, array<string, mixed>> $values */
        $values = $results->take($perPage)->map(fn ($row): array => [
            'raw' => (string) $row->raw_value,
            'mapped' => $row->correction !== null ? (string) $row->correction : null,
            'count' => (int) $row->count,
        ]);

        return [
            'values' => $values,
            'hasMore' => $hasMore,
            'totalFiltered' => $totalFiltered,
        ];
    }

    /**
     * Get counts for each filter type.
     *
     * @return array{all: int, modified: int, skipped: int}
     */
    public function getFilterCounts(string $csvColumn, string $search = ''): array
    {
        $jsonPath = $this->jsonPath($csvColumn);

        $query = $this->store->connection()
            ->table('import_rows')
            ->selectRaw(
                "COUNT(DISTINCT COALESCE(json_extract(raw_data, ?), '')) as all_count,
                 COUNT(DISTINCT CASE WHEN json_extract(corrections, ?) IS NOT NULL AND json_extract(corrections, ?) != '' THEN COALESCE(json_extract(raw_data, ?), '') END) as modified_count,
                 COUNT(DISTINCT CASE WHEN json_extract(corrections, ?) = '' THEN COALESCE(json_extract(raw_data, ?), '') END) as skipped_count",
                [$jsonPath, $jsonPath, $jsonPath, $jsonPath, $jsonPath, $jsonPath]
            );

        if ($search !== '') {
            $query->whereRaw("COALESCE(json_extract(raw_data, ?), '') LIKE ? ESCAPE '\\'", [$jsonPath, $this->escapeLikePattern($search)]);
        }

        $result = $query->first();

        return [
            'all' => (int) ($result->all_count ?? 0),
            'modified' => (int) ($result->modified_count ?? 0),
            'skipped' => (int) ($result->skipped_count ?? 0),
        ];
    }

    /**
     * Skip a value (mark as blank).
     */
    public function skipValue(string $csvColumn, string $value): int
    {
        return $this->applyCorrection($csvColumn, $value, '');
    }

    /**
     * Apply a correction to all rows with a specific value.
     *
     * If restoring to original value, removes the correction instead.
     */
    public function applyCorrection(string $csvColumn, string $oldValue, string $newValue): int
    {
        $jsonPath = $this->jsonPath($csvColumn);
        $pdo = $this->store->connection()->getPdo();
        $newValue = trim($newValue);

        if ($newValue === trim($oldValue)) {
            return $this->store->connection()
                ->table('import_rows')
                ->whereRaw("COALESCE(json_extract(raw_data, ?), '') = ?", [$jsonPath, $oldValue])
                ->update([
                    'corrections' => DB::raw(
                        "json_remove(COALESCE(corrections, '{}'), ".$pdo->quote($jsonPath).')'
                    ),
                ]);
        }

        return $this->store->connection()
            ->table('import_rows')
            ->whereRaw("COALESCE(json_extract(raw_data, ?), '') = ?", [$jsonPath, $oldValue])
            ->update([
                'corrections' => DB::raw(
                    "json_set(COALESCE(corrections, '{}'), ".
                    $pdo->quote($jsonPath).', '.
                    $pdo->quote($newValue).')'
                ),
            ]);
    }

    private function jsonPath(string $csvColumn): string
    {
        return '$.'.str_replace('"', '\"', $csvColumn);
    }

    private function escapeLikePattern(string $search): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
    }
}
