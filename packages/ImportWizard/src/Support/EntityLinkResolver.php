<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Support\CustomFields\CanonicalValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;

final class EntityLinkResolver
{
    private const string CUSTOM_FIELD_PREFIX = 'custom_fields_';

    /** @var array<string, array<string, int|string|null>> */
    private array $cache = [];

    public function __construct(
        private readonly string $workspaceId,
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

        $results = match (true) {
            $link->targetModelClass === User::class => $this->resolveViaWorkspaceMember($field, $uniqueValues),
            $this->isCustomField($field) => $this->resolveViaCustomField($link, $field, $uniqueValues),
            default => $this->resolveViaColumn($link, $field, $uniqueValues),
        };

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
            ->where('workspace_id', $this->workspaceId)
            ->whereIn($field, $uniqueValues)
            ->pluck('id', $field)
            ->all();
    }

    /**
     * @param  array<string>  $uniqueValues
     * @return array<string, int|string>
     */
    private function resolveViaWorkspaceMember(string $field, array $uniqueValues): array
    {
        return User::query()
            ->whereIn($field, $uniqueValues)
            ->where(function (Builder $query): void {
                $query->whereHas('workspaces', fn (Builder $q) => $q->where('workspaces.id', $this->workspaceId))
                    ->orWhereHas('ownedWorkspaces', fn (Builder $q) => $q->where('workspaces.id', $this->workspaceId));
            })
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
            ->where('tenant_id', $this->workspaceId)
            ->where('entity_type', $link->targetEntity)
            ->where('code', $customFieldCode)
            ->first();

        if ($customField === null) {
            return [];
        }

        $valueColumn = $customField->getValueColumn();

        return $valueColumn === 'json_value'
            ? $this->resolveViaJsonColumn($link, $customField, $uniqueValues)
            : CustomFieldValue::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $this->workspaceId)
                ->where('custom_field_id', $customField->id)
                ->where('entity_type', $link->targetEntity)
                ->whereIn('entity_id', $this->accessibleEntities($link))
                ->whereIn($valueColumn, $uniqueValues)
                ->pluck('entity_id', $valueColumn)
                ->all();
    }

    /** @return Builder<Model> */
    private function accessibleEntities(EntityLink $link): Builder
    {
        $modelClass = $link->targetModelClass;

        return $modelClass::query()
            ->select((new $modelClass)->getQualifiedKeyName())
            ->where('workspace_id', $this->workspaceId);
    }

    /**
     * @param  array<string>  $uniqueValues
     * @return array<string, int|string>
     */
    private function resolveViaJsonColumn(EntityLink $link, CustomField $customField, array $uniqueValues): array
    {
        if ($uniqueValues === []) {
            return [];
        }

        $spellingsByOriginal = $this->spellingsByOriginal($customField, $uniqueValues);
        $lookupValues = array_values(array_unique(array_merge(...array_values($spellingsByOriginal))));

        $model = new CustomFieldValue;
        $connection = $model->getConnection();
        $table = $model->getTable();
        $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
        $accessibleEntities = $this->accessibleEntities($link)->toBase();
        $results = [];

        foreach (array_chunk($lookupValues, 5000) as $chunk) {
            $lowerChunk = array_map(mb_strtolower(...), $chunk);
            $placeholders = implode(',', array_fill(0, count($lowerChunk), '?'));

            $sql = $this->jsonValueMatchSql($table, $tenantKey, $placeholders);

            $sql .= " AND cfv.entity_id IN ({$accessibleEntities->toSql()})";
            $bindings = array_merge(
                [$this->workspaceId, $customField->getKey(), $link->targetEntity],
                $lowerChunk,
                $accessibleEntities->getBindings(),
            );
            $rows = $connection->select($sql, $bindings);

            foreach ($rows as $row) {
                $key = mb_strtolower((string) $row->matched_value);

                $results[$key] ??= $row->entity_id;
            }
        }

        $matched = [];

        foreach ($spellingsByOriginal as $original => $spellings) {
            $spelling = array_find([(string) $original, ...$spellings], static fn (string $candidate): bool => isset($results[$candidate]));

            if ($spelling !== null) {
                $matched[(string) $original] = $results[$spelling];
            }
        }

        return $matched;
    }

    private function jsonValueMatchSql(string $table, string $tenantKey, string $placeholders): string
    {
        return "SELECT cfv.entity_id, LOWER(je.value) AS matched_value
                   FROM {$table} cfv
                   CROSS JOIN LATERAL jsonb_array_elements_text(
                       CASE WHEN jsonb_typeof(cfv.json_value::jsonb) = 'array'
                           THEN cfv.json_value::jsonb
                           ELSE jsonb_build_array(cfv.json_value::jsonb)
                       END
                   ) AS je(value)
                   WHERE cfv.{$tenantKey} = ?
                     AND cfv.custom_field_id = ?
                     AND cfv.entity_type = ?
                     AND LOWER(je.value) IN ({$placeholders})";
    }

    /**
     * @param  array<string>  $uniqueValues
     * @return array<string, list<string>>
     */
    private function spellingsByOriginal(CustomField $customField, array $uniqueValues): array
    {
        $spellingsByOriginal = [];

        foreach ($uniqueValues as $value) {
            $spellingsByOriginal[mb_strtolower($value)] = array_map(mb_strtolower(...), CanonicalValue::spellings($customField, $value));
        }

        return $spellingsByOriginal;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, list<string>>
     */
    public function recordsStoring(CustomField $customField, array $values): array
    {
        $valueColumn = $customField->getValueColumn();
        $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
        $model = new CustomFieldValue;
        $recordsByValue = [];

        foreach (array_chunk(array_map(mb_strtolower(...), $values), 5000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $sql = $valueColumn === 'json_value'
                ? $this->jsonValueMatchSql($model->getTable(), $tenantKey, $placeholders)
                : "SELECT cfv.entity_id, LOWER(cfv.{$valueColumn}::text) AS matched_value
                   FROM {$model->getTable()} cfv
                   WHERE cfv.{$tenantKey} = ?
                     AND cfv.custom_field_id = ?
                     AND cfv.entity_type = ?
                     AND LOWER(cfv.{$valueColumn}::text) IN ({$placeholders})";

            $rows = $model->getConnection()->select(
                $sql,
                [$this->workspaceId, $customField->getKey(), $customField->entity_type, ...$chunk],
            );

            foreach ($rows as $row) {
                $recordsByValue[mb_strtolower((string) $row->matched_value)][] = (string) $row->entity_id;
            }
        }

        return $recordsByValue;
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
