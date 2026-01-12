<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Models\Company;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\CustomFields;

/**
 * Matches lookup field values to existing records using unique attributes.
 *
 * For lookup fields (custom fields that reference other entities), this service
 * matches import values against the unique identifying attribute of the target entity:
 * - People: matched by email
 * - Company: matched by domain
 */
final class LookupFieldMatcher
{
    /**
     * Configuration for each entity's unique lookup attribute.
     *
     * @var array<string, array{model: class-string<Model>, field_code: string, value_column: string}>
     */
    private const ENTITY_CONFIG = [
        'people' => [
            'model' => People::class,
            'field_code' => 'emails',
            'value_column' => 'json_value',
        ],
        'company' => [
            'model' => Company::class,
            'field_code' => 'domains',
            'value_column' => 'json_value',
        ],
    ];

    /** @var array<string, array<string, Model>> */
    private array $cache = [];

    private ?string $cachedTeamId = null;

    /**
     * Check if an entity type supports unique attribute matching.
     */
    public function supportsMatching(string $lookupType): bool
    {
        return isset(self::ENTITY_CONFIG[$lookupType]);
    }

    /**
     * Get the unique attribute label for display.
     */
    public function getUniqueAttributeLabel(string $lookupType): ?string
    {
        return match ($lookupType) {
            'people' => 'Email',
            'company' => 'Domain',
            default => null,
        };
    }

    /**
     * Match a value to an existing record by its unique attribute.
     *
     * @return Model|null The matched record, or null if not found
     */
    public function match(string $lookupType, string $value, string $teamId): ?Model
    {
        if (! $this->supportsMatching($lookupType)) {
            return null;
        }

        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return null;
        }

        $this->ensureCacheLoaded($lookupType, $teamId);

        return $this->cache[$lookupType][$value] ?? null;
    }

    /**
     * Batch match multiple values for efficiency.
     *
     * @param  array<string>  $values
     * @return array<string, Model|null> Map of value => matched record
     */
    public function matchBatch(string $lookupType, array $values, string $teamId): array
    {
        if (! $this->supportsMatching($lookupType)) {
            return array_fill_keys($values, null);
        }

        $this->ensureCacheLoaded($lookupType, $teamId);

        $results = [];
        foreach ($values as $value) {
            $normalizedValue = trim(mb_strtolower($value));
            $results[$value] = $normalizedValue !== '' ? ($this->cache[$lookupType][$normalizedValue] ?? null) : null;
        }

        return $results;
    }

    /**
     * Load and cache all records with their unique attribute values.
     */
    private function ensureCacheLoaded(string $lookupType, string $teamId): void
    {
        $cacheKey = "{$lookupType}:{$teamId}";

        if (isset($this->cache[$lookupType]) && $this->cachedTeamId === $cacheKey) {
            return;
        }

        $this->cachedTeamId = $cacheKey;
        $this->cache[$lookupType] = [];

        $config = self::ENTITY_CONFIG[$lookupType];

        // Get the custom field ID for this entity's unique attribute
        $customFieldId = $this->getCustomFieldId($lookupType, $config['field_code'], $teamId);
        if ($customFieldId === null) {
            return;
        }

        // Query all records with their unique attribute values
        /** @var class-string<Model> $modelClass */
        $modelClass = $config['model'];

        $records = $modelClass::query()
            ->where('team_id', $teamId)
            ->get()
            ->keyBy(fn (Model $record): string => (string) $record->getKey());

        if ($records->isEmpty()) {
            return;
        }

        // Get all custom field values for these records
        $valueTable = CustomFields::newValueModel()->getTable();

        $values = DB::table($valueTable)
            ->where('custom_field_id', $customFieldId)
            ->where('tenant_id', $teamId)
            ->whereIn('entity_id', $records->keys())
            ->select('entity_id', $config['value_column'])
            ->get();

        // Build reverse lookup: value -> record
        foreach ($values as $row) {
            $entityId = $row->entity_id;
            $record = $records->get($entityId);

            if (! $record instanceof Model) {
                continue;
            }

            $rawValue = $row->{$config['value_column']};

            // Handle JSON array values (emails, domains can have multiple)
            $attributeValues = $this->extractValues($rawValue);

            foreach ($attributeValues as $attrValue) {
                $normalizedValue = trim(mb_strtolower($attrValue));
                if ($normalizedValue !== '') {
                    // First match wins (if multiple records have same email/domain)
                    $this->cache[$lookupType][$normalizedValue] ??= $record;
                }
            }
        }
    }

    /**
     * Get the custom field ID for an entity's lookup attribute.
     */
    private function getCustomFieldId(string $lookupType, string $fieldCode, string $teamId): ?string
    {
        $field = CustomFields::newCustomFieldModel()::query()
            ->withoutGlobalScopes()
            ->where('entity_type', $lookupType)
            ->where('code', $fieldCode)
            ->where('tenant_id', $teamId)
            ->first();

        return $field?->getKey();
    }

    /**
     * Extract individual values from a JSON array or string.
     *
     * @return array<string>
     */
    private function extractValues(mixed $rawValue): array
    {
        if ($rawValue === null) {
            return [];
        }

        if (is_string($rawValue)) {
            $decoded = json_decode($rawValue, true);
            if (is_array($decoded)) {
                return array_filter($decoded, fn ($v): bool => is_string($v) && $v !== '');
            }

            return $rawValue !== '' ? [$rawValue] : [];
        }

        if (is_array($rawValue)) {
            return array_filter($rawValue, fn ($v): bool => is_string($v) && $v !== '');
        }

        return [];
    }

    /**
     * Clear the cache (useful for testing).
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->cachedTeamId = null;
    }
}
