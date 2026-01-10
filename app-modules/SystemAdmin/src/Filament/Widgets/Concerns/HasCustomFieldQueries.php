<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Concerns;

use App\Enums\CreationSource;
use Illuminate\Support\Facades\DB;

trait HasCustomFieldQueries
{
    /**
     * Get custom field option ID by entity type, field code, and option name
     */
    protected function getCustomFieldOptionId(string $entityType, string $fieldCode, string $optionName): ?int
    {
        return DB::table('custom_field_options as cfo')
            ->join('custom_fields as cf', 'cfo.custom_field_id', '=', 'cf.id')
            ->where('cf.entity_type', $entityType)
            ->where('cf.code', $fieldCode)
            ->whereRaw('LOWER(cfo.name) = ?', [strtolower($optionName)])
            ->value('cfo.id');
    }

    /**
     * Get all completion status option IDs across all tenants using flexible pattern matching
     * Looks for common completion terms like: Done, Completed, Complete, Finished, Closed, etc.
     */
    /**
     * @return array<int, int>
     */
    protected function getCompletionStatusOptionIds(string $entityType, string $fieldCode): array
    {
        $completionPatterns = [
            'Done', 'Completed', 'Complete', 'Finished', 'Closed', 'Resolved', 'Success', 'Won',
        ];

        $optionIds = $this->getOptionIdsByPatterns($entityType, $fieldCode, $completionPatterns);

        return $optionIds ?: $this->getFallbackOptionIds($entityType, $fieldCode);
    }

    /**
     * Get all high priority option IDs across all tenants using flexible pattern matching
     */
    /**
     * @return array<int, int>
     */
    protected function getHighPriorityOptionIds(string $entityType, string $fieldCode): array
    {
        $highPriorityPatterns = [
            'High', 'Urgent', 'Critical', 'Important', 'Immediate', 'Priority',
        ];

        $optionIds = $this->getOptionIdsByPatterns($entityType, $fieldCode, $highPriorityPatterns);

        return $optionIds ?: $this->getFallbackOptionIds($entityType, $fieldCode);
    }

    /**
     * Get multiple custom field option IDs by entity type and field code
     */
    /**
     * @return array<int, string>
     */
    protected function getCustomFieldOptions(string $entityType, string $fieldCode): array
    {
        return DB::table('custom_field_options as cfo')
            ->join('custom_fields as cf', 'cfo.custom_field_id', '=', 'cf.id')
            ->where('cf.entity_type', $entityType)
            ->where('cf.code', $fieldCode)
            ->orderBy('cfo.sort_order')
            ->pluck('cfo.name', 'cfo.id')
            ->toArray();
    }

    /**
     * Build a query to join entity with custom field values by option name
     */
    /**
     * @return \Illuminate\Database\Query\Builder
     */
    protected function queryEntitiesByCustomFieldOption(
        string $tableName,
        string $entityType,
        string $fieldCode,
        string $optionName
    ) {
        return DB::table($tableName)
            ->leftJoin('custom_field_values as cfv', function ($join) use ($entityType, $tableName): void {
                $join->on("{$tableName}.id", '=', 'cfv.entity_id')
                    ->where('cfv.entity_type', $entityType);
            })
            ->leftJoin('custom_fields as cf', function ($join) use ($fieldCode): void {
                $join->on('cfv.custom_field_id', '=', 'cf.id')
                    ->where('cf.code', $fieldCode);
            })
            ->leftJoin('custom_field_options as cfo', 'cfv.integer_value', '=', 'cfo.id')
            ->where('cfo.name', $optionName)
            ->whereNull("{$tableName}.deleted_at")
            ->where("{$tableName}.creation_source", '!=', CreationSource::SYSTEM->value);
    }

    /**
     * Get count of entities with specific custom field option
     */
    protected function countEntitiesWithCustomFieldOption(
        string $tableName,
        string $entityType,
        string $fieldCode,
        string $optionName
    ): int {
        return $this->queryEntitiesByCustomFieldOption($tableName, $entityType, $fieldCode, $optionName)
            ->count();
    }

    /**
     * Get count of completed entities using flexible completion detection across all tenants
     */
    protected function countCompletedEntities(
        string $tableName,
        string $entityType,
        string $fieldCode
    ): int {
        $completionOptionIds = $this->getCompletionStatusOptionIds($entityType, $fieldCode);

        return $completionOptionIds
            ? $this->countEntitiesWithOptionIds($tableName, $entityType, $fieldCode, $completionOptionIds)
            : 0;
    }

    /**
     * Get count of high priority entities using flexible priority detection across all tenants
     */
    protected function countHighPriorityEntities(
        string $tableName,
        string $entityType,
        string $fieldCode
    ): int {
        $highPriorityOptionIds = $this->getHighPriorityOptionIds($entityType, $fieldCode);

        return $highPriorityOptionIds
            ? $this->countEntitiesWithOptionIds($tableName, $entityType, $fieldCode, $highPriorityOptionIds)
            : 0;
    }

    /**
     * @param  array<int, string>  $patterns
     * @return array<int, int>
     */
    private function getOptionIdsByPatterns(string $entityType, string $fieldCode, array $patterns): array
    {
        return DB::table('custom_field_options as cfo')
            ->join('custom_fields as cf', 'cfo.custom_field_id', '=', 'cf.id')
            ->where('cf.entity_type', $entityType)
            ->where('cf.code', $fieldCode)
            ->where(fn (\Illuminate\Contracts\Database\Query\Builder $query) => collect($patterns)->each(fn ($pattern) => $query->orWhereRaw('LOWER(cfo.name) = ?', [strtolower($pattern)])
            )
            )
            ->pluck('cfo.id')
            ->toArray();
    }

    /**
     * @return array<int, int>
     */
    private function getFallbackOptionIds(string $entityType, string $fieldCode): array
    {
        return DB::table('custom_field_options as cfo')
            ->join('custom_fields as cf', 'cfo.custom_field_id', '=', 'cf.id')
            ->where('cf.entity_type', $entityType)
            ->where('cf.code', $fieldCode)
            ->whereIn('cfo.id', fn ($query) => $query->select(DB::raw('MAX(cfo2.id)'))
                ->from('custom_field_options as cfo2')
                ->join('custom_fields as cf2', 'cfo2.custom_field_id', '=', 'cf2.id')
                ->whereRaw('cf2.entity_type = cf.entity_type')
                ->whereRaw('cf2.code = cf.code')
                ->whereRaw('cf2.tenant_id = cf.tenant_id')
                ->groupBy('cf2.tenant_id')
            )
            ->pluck('cfo.id')
            ->toArray();
    }

    /**
     * @param  array<int, int>  $optionIds
     */
    private function countEntitiesWithOptionIds(string $tableName, string $entityType, string $fieldCode, array $optionIds): int
    {
        return DB::table($tableName)
            ->leftJoin('custom_field_values as cfv', fn ($join) => $join->on("{$tableName}.id", '=', 'cfv.entity_id')
                ->where('cfv.entity_type', $entityType)
            )
            ->leftJoin('custom_fields as cf', fn ($join) => $join->on('cfv.custom_field_id', '=', 'cf.id')
                ->where('cf.code', $fieldCode)
            )
            ->whereIn('cfv.integer_value', $optionIds)
            ->whereNull("{$tableName}.deleted_at")
            ->where("{$tableName}.creation_source", '!=', CreationSource::SYSTEM->value)
            ->count();
    }
}
