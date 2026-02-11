<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;

final class EntityLinkResolver
{
    private const CUSTOM_FIELD_PREFIX = 'custom_fields_';

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
            $results = array_merge($results, $this->batchResolve($link, $matcher, $toFetch));
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
            ->withoutGlobalScopes()
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

        $baseQuery = CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->teamId)
            ->where('custom_field_id', $customField->id)
            ->where('entity_type', $link->targetEntity);

        if ($valueColumn === 'json_value') {
            return $this->resolveViaJsonColumn($baseQuery, $uniqueValues);
        }

        return (clone $baseQuery)
            ->whereIn($valueColumn, $uniqueValues)
            ->pluck('entity_id', $valueColumn)
            ->all();
    }

    /**
     * @param  Builder<CustomFieldValue>  $baseQuery
     * @param  array<string>  $uniqueValues
     * @return array<string, int|string>
     */
    private function resolveViaJsonColumn(Builder $baseQuery, array $uniqueValues): array
    {
        $results = [];

        foreach ($uniqueValues as $value) {
            $entityId = (clone $baseQuery)
                ->whereJsonContains('json_value', $value)
                ->value('entity_id');

            if ($entityId !== null) {
                $results[$value] = $entityId;
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
