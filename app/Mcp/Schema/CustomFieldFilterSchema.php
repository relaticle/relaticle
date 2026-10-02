<?php

declare(strict_types=1);

namespace App\Mcp\Schema;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFields\WorkspaceCustomFields;
use App\Support\Filters\CustomFieldSort;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\QueryBuilder\AllowedSort;

final readonly class CustomFieldFilterSchema
{
    public const int MAX_LIST_VALUES = 100;

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
            $schema[$field->code] = [
                'type' => 'object',
                'description' => $field->name,
                'properties' => self::operatorsForType($field->type),
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

        $operators = match ($fieldType) {
            CustomFieldType::TEXT => self::buildOperators(self::STRING_OPERATORS, 'string'),
            CustomFieldType::EMAIL, CustomFieldType::PHONE, CustomFieldType::LINK,
            CustomFieldType::MULTI_SELECT, CustomFieldType::CHECKBOX_LIST, CustomFieldType::TAGS_INPUT => self::listOperators(['has_any', 'has_none']),
            CustomFieldType::CURRENCY => self::buildOperators(self::NUMERIC_OPERATORS, 'number'),
            CustomFieldType::NUMBER => self::buildOperators(self::NUMERIC_OPERATORS, 'integer'),
            CustomFieldType::DATE => self::buildOperators(self::NUMERIC_OPERATORS, 'string', 'date'),
            CustomFieldType::DATE_TIME => self::buildOperators(self::NUMERIC_OPERATORS, 'string', 'date-time'),
            CustomFieldType::CHECKBOX, CustomFieldType::TOGGLE => self::buildOperators(self::BOOLEAN_OPERATORS, 'boolean'),
            CustomFieldType::SELECT, CustomFieldType::RADIO, CustomFieldType::TOGGLE_BUTTONS => [
                ...self::buildOperators(['eq'], 'string'),
                ...self::listOperators(['in', 'not_in']),
            ],
            default => [],
        };

        return $operators === [] ? [] : [...$operators, 'is_empty' => ['type' => 'boolean']];
    }

    /**
     * @param  array<int, string>  $operators
     * @return array<string, array<string, string>>
     */
    private static function buildOperators(array $operators, string $jsonType, ?string $format = null): array
    {
        return array_fill_keys($operators, array_filter(['type' => $jsonType, 'format' => $format]));
    }

    /**
     * @param  array<int, string>  $operators
     * @return array<string, array<string, mixed>>
     */
    private static function listOperators(array $operators): array
    {
        return array_fill_keys($operators, ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => self::MAX_LIST_VALUES]);
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
