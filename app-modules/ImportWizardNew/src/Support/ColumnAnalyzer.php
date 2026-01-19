<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\ImportWizardNew\Data\ColumnAnalysisResult;
use Relaticle\ImportWizardNew\Data\ColumnMapping;
use Relaticle\ImportWizardNew\Data\ImportField;
use Relaticle\ImportWizardNew\Importers\BaseImporter;
use Relaticle\ImportWizardNew\Store\ImportStore;

/**
 * Analyzes column values from SQLite storage.
 */
final class ColumnAnalyzer
{
    public function __construct(
        private readonly ImportStore $store,
        private readonly BaseImporter $importer,
    ) {}

    /**
     * Analyze all mapped columns using a single batched query.
     *
     * @return Collection<string, ColumnAnalysisResult>
     */
    public function analyzeAllColumns(): Collection
    {
        $mappings = $this->store->mappings();

        if ($mappings->isEmpty()) {
            return collect();
        }

        $unionParts = [];
        $bindings = [];

        foreach ($mappings as $mapping) {
            $jsonPath = $this->jsonPath($mapping->source);
            $unionParts[] = "SELECT ? as csv_column,
                COUNT(DISTINCT COALESCE(json_extract(corrections, ?), json_extract(raw_data, ?), '')) as unique_count,
                SUM(CASE WHEN COALESCE(json_extract(corrections, ?), json_extract(raw_data, ?), '') = '' THEN 1 ELSE 0 END) as blank_count
                FROM import_rows";
            $bindings = array_merge($bindings, [$mapping->source, $jsonPath, $jsonPath, $jsonPath, $jsonPath]);
        }

        $sql = implode(' UNION ALL ', $unionParts);
        $statsResults = $this->store->connection()->select($sql, $bindings);

        $statsMap = collect($statsResults)->keyBy('csv_column');
        $totalRows = $this->store->rowCount();
        $results = collect();

        foreach ($mappings as $mapping) {
            $field = $this->getField($mapping);
            $stats = $statsMap->get($mapping->source);

            $result = new ColumnAnalysisResult(
                csvColumn: $mapping->source,
                fieldKey: $mapping->target,
                fieldLabel: $field instanceof ImportField ? $field->label : $mapping->target,
                fieldType: $field?->isCustomField ? 'custom_field' : 'field',
                totalRows: $totalRows,
                uniqueCount: (int) ($stats->unique_count ?? 0),
                blankCount: (int) ($stats->blank_count ?? 0),
                isRequired: $field instanceof ImportField && $field->required,
                relationship: $mapping->relationship,
            );
            $results->put($mapping->source, $result);
        }

        return $results;
    }

    /**
     * Analyze a single column mapping.
     */
    public function analyzeColumn(ColumnMapping $mapping): ColumnAnalysisResult
    {
        $field = $this->getField($mapping);
        $totalRows = $this->store->rowCount();
        $stats = $this->getColumnStats($mapping->source);

        return new ColumnAnalysisResult(
            csvColumn: $mapping->source,
            fieldKey: $mapping->target,
            fieldLabel: $field instanceof ImportField ? $field->label : $mapping->target,
            fieldType: $field?->isCustomField ? 'custom_field' : 'field',
            totalRows: $totalRows,
            uniqueCount: $stats['uniqueCount'],
            blankCount: $stats['blankCount'],
            isRequired: $field instanceof ImportField && $field->required,
            relationship: $mapping->relationship,
        );
    }

    /**
     * Get statistics for a column (unique count and blank count).
     *
     * @return array{uniqueCount: int, blankCount: int}
     */
    private function getColumnStats(string $csvColumn): array
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
     * Get unique raw values for a column with their corrections and row counts.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getUniqueValues(string $csvColumn, int $limit = 100): Collection
    {
        $jsonPath = $this->jsonPath($csvColumn);

        /** @var Collection<int, array<string, mixed>> */
        return $this->store->connection()
            ->table('import_rows')
            ->selectRaw(
                "COALESCE(json_extract(raw_data, ?), '') as raw_value, json_extract(corrections, ?) as correction, COUNT(*) as count",
                [$jsonPath, $jsonPath]
            )
            ->groupBy('raw_value', 'correction')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'raw' => (string) $row->raw_value,
                'mapped' => $row->correction !== null ? (string) $row->correction : null,
                'count' => (int) $row->count,
            ]);
    }

    /**
     * Skip a value (mark as blank).
     */
    public function skipValue(string $csvColumn, string $value): int
    {
        return $this->applyCorrection($csvColumn, $value, '');
    }

    /**
     * Apply a correction to all rows with a specific value using bulk SQL update.
     */
    public function applyCorrection(string $csvColumn, string $oldValue, string $newValue): int
    {
        $jsonPath = $this->jsonPath($csvColumn);
        $pdo = $this->store->connection()->getPdo();

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

    private function getField(ColumnMapping $mapping): ?ImportField
    {
        if ($mapping->isRelationshipMapping()) {
            return null;
        }

        return $this->importer->allFields()->get($mapping->target);
    }

    /**
     * Build a JSON path for SQLite json_extract/json_set.
     */
    private function jsonPath(string $csvColumn): string
    {
        return '$.'.str_replace('"', '\"', $csvColumn);
    }
}
