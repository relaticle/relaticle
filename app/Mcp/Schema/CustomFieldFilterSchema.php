<?php

declare(strict_types=1);

namespace App\Mcp\Schema;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFields\WorkspaceCustomFields;
use App\Support\Filters\CustomFieldSort;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\QueryBuilder\AllowedSort;

final readonly class CustomFieldFilterSchema
{
    public const int MAX_LIST_VALUES = 100;

    /** @var list<string> */
    public const array DOMAIN_OPERATORS = ['$in', '$not_in'];

    public const string EMPTINESS_RULE = '$is_empty takes true or false.';

    public const string EMPTY_MATCH_RULE = '$not_in and $has_none also match records where the field is empty.';

    /** @var array<string, array<string, list<string>>> */
    public const array DOMAIN_EXAMPLE = ['domain' => ['$in' => ['acme.com']]];

    /** @var array<int, string> */
    private const array NUMERIC_OPERATORS = ['$eq', '$gt', '$gte', '$lt', '$lte'];

    /** @var array<int, string> */
    private const array STRING_OPERATORS = ['$eq', '$contains'];

    /** @var array<int, string> */
    private const array BOOLEAN_OPERATORS = ['$eq'];

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
            CustomFieldType::EMAIL, CustomFieldType::LINK => [
                ...self::listOperators(['$has_any', '$has_none']),
                'domain' => ['type' => 'object', 'properties' => self::listOperators(self::DOMAIN_OPERATORS)],
            ],
            CustomFieldType::PHONE,
            CustomFieldType::MULTI_SELECT, CustomFieldType::CHECKBOX_LIST, CustomFieldType::TAGS_INPUT => self::listOperators(['$has_any', '$has_none']),
            CustomFieldType::CURRENCY => self::buildOperators(self::NUMERIC_OPERATORS, 'number'),
            CustomFieldType::NUMBER => self::buildOperators(self::NUMERIC_OPERATORS, 'integer'),
            CustomFieldType::DATE => self::buildOperators(self::NUMERIC_OPERATORS, 'string', 'date'),
            CustomFieldType::DATE_TIME => self::buildOperators(self::NUMERIC_OPERATORS, 'string', 'date-time'),
            CustomFieldType::CHECKBOX, CustomFieldType::TOGGLE => self::buildOperators(self::BOOLEAN_OPERATORS, 'boolean'),
            CustomFieldType::SELECT, CustomFieldType::RADIO, CustomFieldType::TOGGLE_BUTTONS => [
                ...self::buildOperators(['$eq'], 'string'),
                ...self::listOperators(['$in', '$not_in']),
            ],
            default => [],
        };

        return $operators === [] ? [] : [...$operators, '$is_empty' => ['type' => 'boolean']];
    }

    public static function valueRules(): string
    {
        $typesByMatching = [];
        $domainTypes = [];

        foreach (CustomFieldType::cases() as $type) {
            $matching = $type->filterMatching();

            if ($matching !== null) {
                $typesByMatching[$matching][] = $type->value;
            }

            if (isset(self::operatorsForType($type->value)['domain'])) {
                $domainTypes[] = $type->value;
            }
        }

        $sentences = [];

        foreach ($typesByMatching as $matching => $types) {
            $sentences[] = ucfirst(Arr::join($types, ', ', ' and '))." values match {$matching}.";
        }

        $sentences[] = ucfirst(Arr::join($domainTypes, ', ', ' and ')).' fields also take a domain sub-field with '.implode(' or ', self::DOMAIN_OPERATORS).', such as '.self::json(self::DOMAIN_EXAMPLE).', which matches the host of each value.';
        $sentences[] = self::EMPTY_MATCH_RULE;
        $sentences[] = self::EMPTINESS_RULE;

        return implode(' ', $sentences);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    public static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    public static function operatorNames(): array
    {
        $names = [];

        foreach (CustomFieldType::cases() as $type) {
            foreach (array_keys(self::operatorsForType($type->value)) as $operator) {
                if (str_starts_with($operator, '$')) {
                    $names[] = $operator;
                }
            }
        }

        return array_values(array_unique($names));
    }

    public static function operatorSummary(): string
    {
        $typesByOperators = [];

        foreach (CustomFieldType::cases() as $type) {
            $operators = array_filter(
                array_keys(self::operatorsForType($type->value)),
                static fn (string $operator): bool => str_starts_with($operator, '$') && $operator !== '$is_empty',
            );

            if ($operators !== []) {
                $typesByOperators[implode(', ', $operators)][] = $type->value;
            }
        }

        return implode(' ', array_map(
            static fn (string $operators, array $types): string => ucfirst(implode(', ', $types)).": {$operators}.",
            array_keys($typesByOperators),
            $typesByOperators,
        ));
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
