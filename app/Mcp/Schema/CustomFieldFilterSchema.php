<?php

declare(strict_types=1);

namespace App\Mcp\Schema;

use App\Enums\CustomFieldType;
use App\Mcp\Filters\CustomFieldSort;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\QueryBuilder\AllowedSort;

final readonly class CustomFieldFilterSchema
{
    /** @var array<int, string> */
    private const array EXCLUDED_TYPES = [
        CustomFieldType::FILE_UPLOAD->value,
        CustomFieldType::RECORD->value,
        CustomFieldType::TEXTAREA->value,
        CustomFieldType::RICH_EDITOR->value,
        CustomFieldType::MARKDOWN_EDITOR->value,
    ];

    /** @var array<int, string> */
    private const array NUMERIC_OPERATORS = ['eq', 'gt', 'gte', 'lt', 'lte'];

    /** @var array<int, string> */
    private const array STRING_OPERATORS = ['eq', 'contains'];

    /** @var array<int, string> */
    private const array BOOLEAN_OPERATORS = ['eq'];

    /** @var array<int, string> */
    private const array MULTI_OPERATORS = ['has_any'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function build(User $user, string $entityType): array
    {
        $fields = $this->resolveFilterableFields($user, $entityType);
        $schema = [];

        foreach ($fields as $field) {
            $operators = $this->operatorsForType($field->type);

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

    /**
     * @return array<string, array<string, string|array<string, mixed>>>
     */
    private function operatorsForType(string $type): array
    {
        $fieldType = CustomFieldType::tryFrom($type);

        if ($fieldType === null) {
            return [];
        }

        return match ($fieldType) {
            CustomFieldType::TEXT, CustomFieldType::EMAIL, CustomFieldType::PHONE, CustomFieldType::LINK => $this->buildOperators(self::STRING_OPERATORS, 'string'),
            CustomFieldType::CURRENCY => $this->buildOperators(self::NUMERIC_OPERATORS, 'number'),
            CustomFieldType::NUMBER => $this->buildOperators(self::NUMERIC_OPERATORS, 'integer'),
            CustomFieldType::DATE => $this->buildOperators(self::NUMERIC_OPERATORS, 'string'),
            CustomFieldType::DATE_TIME => $this->buildOperators(self::NUMERIC_OPERATORS, 'string'),
            CustomFieldType::CHECKBOX, CustomFieldType::TOGGLE => $this->buildOperators(self::BOOLEAN_OPERATORS, 'boolean'),
            CustomFieldType::SELECT, CustomFieldType::RADIO, CustomFieldType::TOGGLE_BUTTONS => array_merge(
                $this->buildOperators(['eq'], 'string'),
                ['in' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ),
            CustomFieldType::MULTI_SELECT, CustomFieldType::CHECKBOX_LIST, CustomFieldType::TAGS_INPUT => $this->buildOperators(self::MULTI_OPERATORS, 'string'),
            default => [],
        };
    }

    /**
     * @param  array<int, string>  $operators
     * @return array<string, array<string, string>>
     */
    private function buildOperators(array $operators, string $jsonType): array
    {
        $result = [];

        foreach ($operators as $op) {
            $result[$op] = ['type' => $jsonType];
        }

        return $result;
    }

    /**
     * @return Collection<int, CustomField>
     */
    private function resolveFilterableFields(User $user, string $entityType): Collection
    {
        $teamId = $user->currentTeam->getKey();
        $cacheKey = "custom_fields_filter_schema_{$teamId}_{$entityType}";

        /** @var Collection<int, CustomField> */
        return Cache::remember($cacheKey, 60, fn (): Collection => CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', $entityType)
            ->whereNotIn('type', self::EXCLUDED_TYPES)
            ->where(fn (Builder $q) => $q->whereNull('settings->encrypted')->orWhere('settings->encrypted', false))
            ->active()
            ->select('id', 'code', 'name', 'type')
            ->get());
    }
}
