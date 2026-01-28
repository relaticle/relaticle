<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;

/**
 * Resolves import values to record IDs for entity links.
 *
 * Performs efficient batch lookups against the database to find matching
 * records based on the selected matcher field (id, email, domain, name, etc.).
 */
final class EntityLinkResolver
{
    private const CUSTOM_FIELD_PREFIX = 'custom_fields_';

    /** @var array<string, array<string, int|string|null>> */
    private array $cache = [];

    public function __construct(
        private readonly string $teamId,
    ) {}

    /**
     * Resolve a single value to record ID.
     */
    public function resolve(EntityLink $link, MatchableField $matcher, mixed $value): int|string|null
    {
        $value = $this->normalizeValue($value);

        if ($value === '' || $value === null) {
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
     * Resolve multiple values at once, returning map of value => ID.
     *
     * @param  array<mixed>  $values
     * @return array<string, int|string|null>
     */
    public function resolveMany(EntityLink $link, MatchableField $matcher, array $values): array
    {
        $normalized = array_map(fn ($v) => $this->normalizeValue($v), $values);
        $uniqueValues = array_filter(array_unique($normalized), fn ($v) => $v !== '' && $v !== null);

        if (empty($uniqueValues)) {
            return [];
        }

        $cacheKey = $this->getCacheKey($link, $matcher);
        $results = [];
        $toFetch = [];

        foreach ($uniqueValues as $value) {
            if (isset($this->cache[$cacheKey][$value])) {
                $results[$value] = $this->cache[$cacheKey][$value];
            } else {
                $toFetch[] = $value;
            }
        }

        if (! empty($toFetch)) {
            $fetched = $this->batchResolve($link, $matcher, $toFetch);
            $results = array_merge($results, $fetched);
        }

        return $results;
    }

    /**
     * Batch resolve unique values to record IDs with a single database query.
     *
     * @param  array<string>  $uniqueValues
     * @return array<string, int|string|null>
     */
    public function batchResolve(EntityLink $link, MatchableField $matcher, array $uniqueValues): array
    {
        if (empty($uniqueValues)) {
            return [];
        }

        $field = $matcher->field;
        $cacheKey = $this->getCacheKey($link, $matcher);

        $results = $this->isCustomField($field)
            ? $this->resolveViaCustomField($link, $field, $uniqueValues)
            : $this->resolveViaColumn($link, $field, $uniqueValues);

        $resolved = [];

        foreach ($uniqueValues as $value) {
            $normalizedValue = $this->normalizeForComparison($value);
            $matchedId = null;

            foreach ($results as $dbValue => $id) {
                if ($this->normalizeForComparison((string) $dbValue) === $normalizedValue) {
                    $matchedId = $id;
                    break;
                }
            }

            $resolved[$value] = $matchedId;
            $this->cache[$cacheKey][$value] = $matchedId;
        }

        return $resolved;
    }

    /**
     * Check if the field is a custom field accessor.
     */
    private function isCustomField(string $field): bool
    {
        return str_starts_with($field, self::CUSTOM_FIELD_PREFIX);
    }

    /**
     * Extract custom field code from accessor name.
     */
    private function getCustomFieldCode(string $field): string
    {
        return substr($field, strlen(self::CUSTOM_FIELD_PREFIX));
    }

    /**
     * Resolve values via a regular database column.
     *
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
     * Resolve values via custom field values table.
     *
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

        return CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->teamId)
            ->where('custom_field_id', $customField->id)
            ->where('entity_type', $link->targetEntity)
            ->whereIn('string_value', $uniqueValues)
            ->pluck('entity_id', 'string_value')
            ->all();
    }

    /**
     * Preload cache with values for a specific entity link/matcher combination.
     *
     * @param  array<mixed>  $values
     */
    public function preloadCache(EntityLink $link, MatchableField $matcher, array $values): void
    {
        $normalized = array_map(fn ($v) => $this->normalizeValue($v), $values);
        $uniqueValues = array_filter(array_unique($normalized), fn ($v) => $v !== '' && $v !== null);

        if (! empty($uniqueValues)) {
            $this->batchResolve($link, $matcher, $uniqueValues);
        }
    }

    /**
     * Get a resolved ID from cache (returns null if not cached).
     */
    public function getCachedId(EntityLink $link, MatchableField $matcher, mixed $value): int|string|null
    {
        $cacheKey = $this->getCacheKey($link, $matcher);
        $normalized = $this->normalizeValue($value);

        return $this->cache[$cacheKey][$normalized] ?? null;
    }

    /**
     * Clear the in-memory cache.
     */
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

        return trim((string) $value);
    }

    private function normalizeForComparison(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
