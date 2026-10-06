<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\User;
use App\Queries\Sorts\CustomFieldSort;
use App\Support\CustomFields\CustomFieldSchemaCache;
use App\Support\CustomFields\WorkspaceCustomFields;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\QueryBuilder\AllowedSort;

final readonly class CustomFieldFilterSchema
{
    public const int MAX_LIST_VALUES = 100;

    /** @var list<string> */
    public const array DOMAIN_OPERATORS = ['$in', '$not_in'];

    public const string DOMAIN_MEANING = 'the host of each value';

    public const string EMPTINESS_RULE = '$is_empty takes true or false.';

    public const string EMPTY_MATCH_RULE = '$not_in and $has_none also match records where the field is empty.';

    /** @var array<string, array<string, list<string>>> */
    public const array DOMAIN_EXAMPLE = ['domain' => ['$in' => ['acme.com']]];

    /** @var array<string, string> */
    public const array COMPARISONS = ['$eq' => '=', '$gt' => '>', '$gte' => '>=', '$lt' => '<', '$lte' => '<='];

    /** @var array<int, string> */
    private const array STRING_OPERATORS = ['$eq', '$contains'];

    /** @var array<int, string> */
    private const array BOOLEAN_OPERATORS = ['$eq'];

    /**
     * @return array<int, AllowedSort>
     */
    public function allowedSorts(User $user, string $entityType): array
    {
        return $this->sortableFields($user, $entityType)
            ->map(fn (CustomField $field): AllowedSort => AllowedSort::custom($field->code, new CustomFieldSort($field)))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function sortableCodes(User $user, string $entityType): array
    {
        return $this->sortableFields($user, $entityType)
            ->keys()
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
                ...self::buildOperators(['$has_any', '$has_none'], 'array'),
                'domain' => ['type' => 'object', 'properties' => self::buildOperators(self::DOMAIN_OPERATORS, 'array')],
            ],
            CustomFieldType::PHONE, CustomFieldType::DOMAIN,
            CustomFieldType::MULTI_SELECT, CustomFieldType::CHECKBOX_LIST, CustomFieldType::TAGS_INPUT => self::buildOperators(['$has_any', '$has_none'], 'array'),
            CustomFieldType::CURRENCY => self::buildOperators(array_keys(self::COMPARISONS), 'number'),
            CustomFieldType::NUMBER => self::buildOperators(array_keys(self::COMPARISONS), 'integer'),
            CustomFieldType::DATE => self::buildOperators(array_keys(self::COMPARISONS), 'string', 'date'),
            CustomFieldType::DATE_TIME => self::buildOperators(array_keys(self::COMPARISONS), 'string', 'date-time'),
            CustomFieldType::CHECKBOX, CustomFieldType::TOGGLE => self::buildOperators(self::BOOLEAN_OPERATORS, 'boolean'),
            CustomFieldType::SELECT, CustomFieldType::RADIO, CustomFieldType::TOGGLE_BUTTONS => [
                ...self::buildOperators(['$eq'], 'string'),
                ...self::buildOperators(['$in', '$not_in'], 'array'),
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

        $sentences[] = ucfirst(Arr::join($domainTypes, ', ', ' and ')).' fields also take a domain sub-field with '.implode(' or ', self::DOMAIN_OPERATORS).', such as '.self::json(self::DOMAIN_EXAMPLE).', which matches '.self::DOMAIN_MEANING.'.';
        $sentences[] = self::generalRules();

        return implode(' ', $sentences);
    }

    public static function generalRules(): string
    {
        return self::EMPTY_MATCH_RULE.' '.self::EMPTINESS_RULE;
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
    public static function operatorKeys(string $type): array
    {
        return array_values(array_filter(
            array_keys(self::operatorsForType($type)),
            static fn (string $key): bool => str_starts_with($key, '$'),
        ));
    }

    /**
     * @return list<string>
     */
    public static function operatorNames(): array
    {
        $names = [];

        foreach (CustomFieldType::cases() as $type) {
            array_push($names, ...self::operatorKeys($type->value));
        }

        return array_values(array_unique($names));
    }

    public static function operatorSummary(): string
    {
        $typesByOperators = [];

        foreach (CustomFieldType::cases() as $type) {
            $operators = array_filter(
                self::operatorKeys($type->value),
                static fn (string $operator): bool => $operator !== '$is_empty',
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
     * @return Collection<string, CustomField>
     */
    private function sortableFields(User $user, string $entityType): Collection
    {
        // Postgres has no ordering operator for the json column that holds list values.
        return collect(resolve(WorkspaceCustomFields::class)->remember(
            $user->currentWorkspace,
            "sortable_fields:{$entityType}",
            fn (): array => $this->resolveFilterableFields($user, $entityType)
                ->reject(fn (CustomField $field): bool => $field->getValueColumn() === 'json_value')
                ->keyBy('code')
                ->all(),
        ));
    }

    /**
     * @return Collection<int, CustomField>
     */
    private function resolveFilterableFields(User $user, string $entityType): Collection
    {
        $workspace = $user->currentWorkspace;
        $cacheKey = CustomFieldSchemaCache::filterSchemaKey($workspace->getKey(), $entityType);

        /** @var Collection<int, CustomField> */
        return Cache::remember($cacheKey, CustomFieldSchemaCache::TTL, fn (): Collection => resolve(WorkspaceCustomFields::class)
            ->forEntity($workspace, $entityType)
            ->filter(self::isFilterable(...))
            ->map(fn (CustomField $field): CustomField => $field->withoutRelations())
            ->values());
    }
}
