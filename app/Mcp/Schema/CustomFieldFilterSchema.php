<?php

declare(strict_types=1);

namespace App\Mcp\Schema;

use App\Enums\CustomFieldType;
use App\Mcp\Filters\CustomFieldSort;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFields\WorkspaceCustomFields;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\QueryBuilder\AllowedSort;

final readonly class CustomFieldFilterSchema
{
    public const int MAX_LIST_VALUES = 100;

    /** @var array<int, string> */
    private const array EXCLUDED_TYPES = [
        CustomFieldType::FILE_UPLOAD->value,
        CustomFieldType::RECORD->value,
        CustomFieldType::TEXTAREA->value,
        CustomFieldType::RICH_EDITOR->value,
    ];

    /** @var array<int, string> */
    private const array NUMERIC_OPERATORS = ['eq', 'gt', 'gte', 'lt', 'lte'];

    /** @var array<int, string> */
    private const array STRING_OPERATORS = ['eq', 'contains'];

    /** @var array<int, string> */
    private const array BOOLEAN_OPERATORS = ['eq'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function build(User $user, string $entityType): array
    {
        $fields = $this->resolveFilterableFields($user, $entityType);
        $schema = [];

        foreach ($fields as $field) {
            $operators = self::operatorsForType($field->type);

            if ($operators === []) {
                continue;
            }

            $schema[$field->code] = [
                'type' => 'object',
                'description' => $field->name,
                'properties' => $operators,
            ];
        }

        return $schema;
    }

    /**
     * @return array<int, AllowedSort>
     */
    public function allowedSorts(User $user, string $entityType): array
    {
        return collect(array_keys($this->build($user, $entityType)))
            ->map(fn (string $code): AllowedSort => AllowedSort::custom($code, new CustomFieldSort($entityType)))
            ->all();
    }

    public static function isFilterable(CustomField $field): bool
    {
        return $field->active
            && ! in_array($field->type, self::EXCLUDED_TYPES, true)
            && ! $field->settings->encrypted
            && self::operatorsForType($field->type) !== [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function operatorsForType(string $type): array
    {
        $fieldType = CustomFieldType::tryFrom($type);

        if ($fieldType === null) {
            return [];
        }

        return match ($fieldType) {
            CustomFieldType::TEXT => self::withEmptiness(self::buildOperators(self::STRING_OPERATORS, 'string')),
            CustomFieldType::EMAIL, CustomFieldType::PHONE, CustomFieldType::LINK,
            CustomFieldType::MULTI_SELECT, CustomFieldType::CHECKBOX_LIST, CustomFieldType::TAGS_INPUT => self::withEmptiness(self::listOperators(['has_any', 'has_none'])),
            CustomFieldType::CURRENCY => self::withEmptiness(self::buildOperators(self::NUMERIC_OPERATORS, 'number')),
            CustomFieldType::NUMBER => self::withEmptiness(self::buildOperators(self::NUMERIC_OPERATORS, 'integer')),
            CustomFieldType::DATE, CustomFieldType::DATE_TIME => self::withEmptiness(self::buildOperators(self::NUMERIC_OPERATORS, 'string')),
            CustomFieldType::CHECKBOX, CustomFieldType::TOGGLE => self::withEmptiness(self::buildOperators(self::BOOLEAN_OPERATORS, 'boolean')),
            CustomFieldType::SELECT, CustomFieldType::RADIO, CustomFieldType::TOGGLE_BUTTONS => self::withEmptiness(array_merge(
                self::buildOperators(['eq'], 'string'),
                self::listOperators(['in', 'not_in']),
            )),
            default => [],
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $operators
     * @return array<string, array<string, mixed>>
     */
    private static function withEmptiness(array $operators): array
    {
        return [...$operators, 'is_empty' => ['type' => 'boolean']];
    }

    /**
     * @param  array<int, string>  $operators
     * @return array<string, array<string, string>>
     */
    private static function buildOperators(array $operators, string $jsonType): array
    {
        $result = [];

        foreach ($operators as $op) {
            $result[$op] = ['type' => $jsonType];
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $operators
     * @return array<string, array<string, mixed>>
     */
    private static function listOperators(array $operators): array
    {
        $result = [];

        foreach ($operators as $op) {
            $result[$op] = ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => self::MAX_LIST_VALUES];
        }

        return $result;
    }

    /**
     * @return Collection<int, CustomField>
     */
    private function resolveFilterableFields(User $user, string $entityType): Collection
    {
        $workspace = $user->currentWorkspace;
        $cacheKey = McpSchemaCache::filterSchemaKey($workspace->getKey(), $entityType);

        /** @var Collection<int, CustomField> */
        return Cache::remember($cacheKey, McpSchemaCache::TTL, fn (): Collection => resolve(WorkspaceCustomFields::class)
            ->forEntity($workspace, $entityType)
            ->filter(self::isFilterable(...))
            ->map(fn (CustomField $field): CustomField => $field->withoutRelations())
            ->values());
    }
}
