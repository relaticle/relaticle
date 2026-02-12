<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;

final class EntityLinkResolver
{
    private const string CUSTOM_FIELD_PREFIX = 'custom_fields_';

    /** @var array<string, array<string, int|string|null>> */
    private array $cache = [];

    public function __construct(
        private readonly string $teamId,
    ) {}

    public function resolve(EntityLink $link, MatchableField $matcher, mixed $value): int|string|null
    {
        $value = $this->normalizeValue($value);

        if ($value === null) {
            return null;
        }

        $cacheKey = $this->getCacheKey($link, $matcher);

        if (isset($this->cache[$cacheKey][$value])) {
            return $this->cache[$cacheKey][$value];
        }

        $resolved = $this->batchResolve($link, $matcher, [$value]);

        return $resolved[$value] ?? null;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, int|string|null>
     */
    public function resolveMany(EntityLink $link, MatchableField $matcher, array $values): array
    {
        $uniqueValues = $this->normalizeUniqueValues($values);

        if ($uniqueValues === []) {
            return [];
        }

        $cacheKey = $this->getCacheKey($link, $matcher);
        $results = [];
        $toFetch = [];

        foreach ($uniqueValues as $value) {
            if (! isset($this->cache[$cacheKey][$value])) {
                $toFetch[] = $value;

                continue;
            }

            $results[$value] = $this->cache[$cacheKey][$value];
        }

        if ($toFetch !== []) {
            return array_merge($results, $this->batchResolve($link, $matcher, $toFetch));
        }

        return $results;
    }

    /**
     * @param  array<string>  $uniqueValues
     * @return array<string, int|string|null>
     */
    public function batchResolve(EntityLink $link, MatchableField $matcher, array $uniqueValues): array
    {
        if ($uniqueValues === []) {
            return [];
        }

        $field = $matcher->field;
        $cacheKey = $this->getCacheKey($link, $matcher);

        $results = $this->isCustomField($field)
            ? $this->resolveViaCustomField($link, $field, $uniqueValues)
            : $this->resolveViaColumn($link, $field, $uniqueValues);

        $normalizedResults = [];
        foreach ($results as $dbValue => $id) {
            $normalizedResults[$this->normalizeForComparison((string) $dbValue)] = $id;
        }

        $resolved = [];

        foreach ($uniqueValues as $value) {
            $matchedId = $normalizedResults[$this->normalizeForComparison($value)] ?? null;
            $resolved[$value] = $matchedId;
            $this->cache[$cacheKey][$value] = $matchedId;
        }

        return $resolved;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string>
     */
    private function normalizeUniqueValues(array $values): array
    {
        return collect($values)
            ->map(fn (mixed $v): ?string => $this->normalizeValue($v))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isCustomField(string $field): bool
    {
        return str_starts_with($field, self::CUSTOM_FIELD_PREFIX);
    }

    private function getCustomFieldCode(string $field): string
    {
        return substr($field, strlen(self::CUSTOM_FIELD_PREFIX));
    }

    /**
     * @param  array<string>  $uniqueValues
     * @return array<string, int|string>
     */
    private function resolveViaColumn(EntityLink $link, string $field, array $uniqueValues): array
    {
        $modelClass = $link->targetModelClass;

        return $modelClass::query()
            ->where('team_id', $this->teamId)
            ->whereIn($field, $uniqueValues)
            ->pluck('id', $field)
            ->all();
    }

    /**
     * @param  array<string>  $uniqueValues
     * @return array<string, int|string>
     */
    private function resolveViaCustomField(EntityLink $link, string $field, array $uniqueValues): array
    {
        $customFieldCode = $this->getCustomFieldCode($field);

        $customField = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->teamId)
            ->where('entity_type', $link->targetEntity)
            ->where('code', $customFieldCode)
            ->first();

        if ($customField === null) {
            return [];
        }

        $valueColumn = $customField->getValueColumn();

        if ($valueColumn === 'json_value') {
            return $this->resolveViaJsonColumn($link->targetEntity, $customField->getKey(), $uniqueValues);
        }

        return CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->teamId)
            ->where('custom_field_id', $customField->id)
            ->where('entity_type', $link->targetEntity)
            ->whereIn($valueColumn, $uniqueValues)
            ->pluck('entity_id', $valueColumn)
            ->all();
    }

    /**
     * @param  array<string>  $uniqueValues
     * @return array<string, int|string>
     */
    private function resolveViaJsonColumn(string $entityType, int|string $customFieldId, array $uniqueValues): array
    {
        if ($uniqueValues === []) {
            return [];
        }

        $model = new CustomFieldValue;
        $connection = $model->getConnection();
        $table = $model->getTable();
        $driver = $connection->getDriverName();
        $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
        $results = [];

        foreach (array_chunk($uniqueValues, 5000) as $chunk) {
            $lowerChunk = array_map(fn (string $v): string => mb_strtolower($v), $chunk);
            $placeholders = implode(',', array_fill(0, count($lowerChunk), '?'));

            $sql = match ($driver) {
                'sqlite' => "SELECT cfv.entity_id, je.value AS matched_value
                   FROM {$table} cfv, json_each(
                       CASE WHEN JSON_TYPE(cfv.json_value) = 'array'
                           THEN cfv.json_value
                           ELSE JSON_ARRAY(cfv.json_value)
                       END
                   ) je
                   WHERE cfv.{$tenantKey} = ?
                     AND cfv.custom_field_id = ?
                     AND cfv.entity_type = ?
                     AND LOWER(CAST(je.value AS TEXT)) IN ({$placeholders})",
                'pgsql' => "SELECT cfv.entity_id, LOWER(je.value) AS matched_value
                   FROM {$table} cfv
                   CROSS JOIN LATERAL jsonb_array_elements_text(
                       CASE WHEN jsonb_typeof(cfv.json_value) = 'array'
                           THEN cfv.json_value
                           ELSE jsonb_build_array(cfv.json_value)
                       END
                   ) AS je(value)
                   WHERE cfv.{$tenantKey} = ?
                     AND cfv.custom_field_id = ?
                     AND cfv.entity_type = ?
                     AND LOWER(je.value) IN ({$placeholders})",
                default => "SELECT cfv.entity_id, jt.val AS matched_value
                   FROM {$table} cfv
                   JOIN JSON_TABLE(
                       IF(JSON_TYPE(cfv.json_value) = 'ARRAY', cfv.json_value, JSON_ARRAY(cfv.json_value)),
                       '\$[*]' COLUMNS(val TEXT PATH '\$')
                   ) AS jt
                   WHERE cfv.{$tenantKey} = ?
                     AND cfv.custom_field_id = ?
                     AND cfv.entity_type = ?
                     AND LOWER(jt.val) IN ({$placeholders})",
            };

            $bindings = array_merge([$this->teamId, $customFieldId, $entityType], $lowerChunk);
            $rows = $connection->select($sql, $bindings);

            foreach ($rows as $row) {
                $key = mb_strtolower($row->matched_value);

                if (! isset($results[$key])) {
                    $results[$key] = $row->entity_id;
                }
            }
        }

        return $results;
    }

    /** @param  array<mixed>  $values */
    public function preloadCache(EntityLink $link, MatchableField $matcher, array $values): void
    {
        $uniqueValues = $this->normalizeUniqueValues($values);

        if ($uniqueValues === []) {
            return;
        }

        $this->batchResolve($link, $matcher, $uniqueValues);
    }

    public function getCachedId(EntityLink $link, MatchableField $matcher, mixed $value): int|string|null
    {
        $cacheKey = $this->getCacheKey($link, $matcher);
        $normalized = $this->normalizeValue($value);

        return $this->cache[$cacheKey][$normalized] ?? null;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    private function getCacheKey(EntityLink $link, MatchableField $matcher): string
    {
        return "{$link->key}:{$matcher->field}";
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeForComparison(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
