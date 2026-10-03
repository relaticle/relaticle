<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Support\CustomFields\CustomFieldOptionMap;
use App\Support\CustomFields\WorkspaceCustomFields;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class CustomFieldFilter implements Filter
{
    private const int MAX_CONDITIONS = 10;

    private const array OPERATOR_MAP = [
        '$eq' => '=',
        '$gt' => '>',
        '$gte' => '>=',
        '$lt' => '<',
        '$lte' => '<=',
    ];

    public function __construct(
        private string $entityType,
        private User $user,
    ) {}

    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        if ($value === []) {
            return;
        }

        if (! is_array($value)) {
            $this->invalid(__('validation.custom_field.filter_not_object'));
        }

        $fieldCodes = array_keys($value);

        if (! array_all($fieldCodes, static fn (mixed $fieldCode): bool => is_string($fieldCode))) {
            $this->invalid(__('validation.custom_field.filter_code_not_string'));
        }

        if (count($fieldCodes) > self::MAX_CONDITIONS) {
            $this->invalid(__('validation.custom_field.too_many_conditions', ['max' => self::MAX_CONDITIONS]));
        }

        $fields = $this->filterableFields();

        $unknownFieldCode = array_first(array_diff($fieldCodes, $fields->keys()->all()));

        if ($unknownFieldCode !== null) {
            $this->invalid(__('validation.custom_field.unknown_filter_field', [
                'field' => $unknownFieldCode,
                'entity' => $this->entityType,
                'available' => $fields->isEmpty() ? 'none' : $fields->keys()->implode(', '),
            ]), (string) $unknownFieldCode);
        }

        $optionMap = resolve(CustomFieldOptionMap::class);
        $options = $optionMap->fromFields(
            $fields->filter(fn (CustomField $field): bool => in_array($field->code, $fieldCodes, true) && $optionMap->translates($field))->values(),
        );

        foreach ($value as $fieldCode => $operators) {
            if (! is_array($operators) || $operators === []) {
                $this->invalid(__('validation.custom_field.operator_object', ['field' => $fieldCode]), (string) $fieldCode);
            }

            $field = $fields[$fieldCode];
            $valueColumn = CustomFieldValue::getValueColumn($field->type);
            $supportedOperators = CustomFieldFilterSchema::operatorsForType($field->type);

            $entry = $options[$fieldCode] ?? null;

            foreach ($operators as $operator => $operand) {
                if (! str_starts_with((string) $operator, '$') && isset($supportedOperators['$'.$operator])) {
                    $this->invalid(__('validation.filter.operator_sigil', ['operator' => '$'.$operator]), "{$fieldCode}.{$operator}");
                }

                if (! isset($supportedOperators[$operator])) {
                    $this->invalid(__('validation.custom_field.unsupported_filter_operator', [
                        'operator' => $operator,
                        'field' => $fieldCode,
                        'supported' => implode(', ', array_keys($supportedOperators)),
                    ]), "{$fieldCode}.{$operator}");
                }

                $operand = $this->normalizeOperand($fieldCode, $operator, $operand, $supportedOperators[$operator], $entry !== null);
                $operand = $this->resolveOptions($optionMap, $fieldCode, $operator, $entry, $operand);

                $this->applyCondition($query, $field, $valueColumn, $operator, $operand);
            }
        }
    }

    /**
     * Coerce an operand to the type its schema declares. MCP and chat clients send
     * typed JSON, but the REST API receives the same filters as query strings where
     * every operand arrives as a string, so a strict type check alone would reject
     * every REST request.
     *
     * @param  array<string, mixed>  $operatorSchema
     */
    private function normalizeOperand(string $fieldCode, string $operator, mixed $operand, array $operatorSchema, bool $splitsStrings): mixed
    {
        $type = $operatorSchema['type'] ?? null;

        $normalized = match ($type) {
            'array' => Operand::stringList($operand, $splitsStrings),
            'boolean' => Operand::boolean($operand),
            'integer' => Operand::integer($operand),
            'number' => Operand::number($operand),
            'string' => $this->formattedString($operand, $operatorSchema['format'] ?? null),
            default => null,
        };

        if (is_array($normalized) && count($normalized) > CustomFieldFilterSchema::MAX_LIST_VALUES) {
            $this->invalid(__('validation.custom_field.too_many_values', [
                'field' => $fieldCode,
                'max' => CustomFieldFilterSchema::MAX_LIST_VALUES,
            ]), "{$fieldCode}.{$operator}");
        }

        if ($normalized !== null) {
            return $normalized;
        }

        $expected = match (true) {
            $type === 'array' => 'an array of strings',
            $type === 'integer' => 'an integer',
            isset($operatorSchema['format']) => "a {$operatorSchema['format']}",
            default => "a {$type}",
        };
        $this->invalid(__('validation.custom_field.operand_type', [
            'field' => $fieldCode,
            'operator' => $operator,
            'expected' => $expected,
        ]), "{$fieldCode}.{$operator}");
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}|null  $entry
     */
    private function resolveOptions(CustomFieldOptionMap $optionMap, string $fieldCode, string $operator, ?array $entry, mixed $operand): mixed
    {
        if ($entry === null || is_bool($operand)) {
            return $operand;
        }

        if (is_array($operand)) {
            return array_map(fn (string $value): string => $this->optionId($optionMap, $fieldCode, $operator, $entry, $value), $operand);
        }

        return $this->optionId($optionMap, $fieldCode, $operator, $entry, (string) $operand);
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function optionId(CustomFieldOptionMap $optionMap, string $fieldCode, string $operator, array $entry, string $value): string
    {
        $id = $optionMap->idFor($entry, $value);

        if ($id !== null) {
            return $id;
        }

        if ($optionMap->isAmbiguous($entry, $value)) {
            $this->invalid(__('validation.custom_field.ambiguous_option', ['field' => $fieldCode, 'value' => $value]), "{$fieldCode}.{$operator}");
        }

        $this->invalid(__('validation.custom_field.unknown_option', [
            'field' => $fieldCode,
            'value' => $value,
            'labels' => $entry['labels'] === [] ? 'none' : implode(', ', $entry['labels']),
        ]), "{$fieldCode}.{$operator}");
    }

    private function formattedString(mixed $operand, ?string $format): ?string
    {
        return match ($format) {
            'date' => Operand::date($operand)?->toDateString(),
            'date-time' => Operand::date($operand)?->toDateTimeString(),
            default => Operand::string($operand),
        };
    }

    private function invalid(string $message, string $path = ''): never
    {
        throw FilterErrors::at($path, $message);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyCondition(
        Builder $query,
        CustomField $field,
        string $valueColumn,
        string $operator,
        mixed $operand,
    ): void {
        match ($operator) {
            '$not_in' => $query->whereDoesntHave('customFieldValues', fn (Builder $q): Builder => $q
                ->where('custom_field_id', $field->getKey())
                ->whereIn($valueColumn, $operand)),
            '$has_none' => $query->whereDoesntHave('customFieldValues', function (Builder $q) use ($field, $valueColumn, $operand): void {
                $q->where('custom_field_id', $field->getKey());
                $this->containsAny($q, $valueColumn, $operand);
            }),
            '$is_empty' => $operand === true
                ? $query->whereDoesntHave('customFieldValues', fn (Builder $q): Builder => $this->hasValue($q, $field, $valueColumn))
                : $query->whereHas('customFieldValues', fn (Builder $q): Builder => $this->hasValue($q, $field, $valueColumn)),
            default => $query->whereHas('customFieldValues', function (Builder $q) use ($field, $valueColumn, $operator, $operand): void {
                $q->where('custom_field_id', $field->getKey());

                match ($operator) {
                    '$eq', '$gt', '$gte', '$lt', '$lte' => $q->where($valueColumn, self::OPERATOR_MAP[$operator], $operand),
                    '$contains' => $q->where($valueColumn, 'ILIKE', '%'.LikePattern::escape((string) $operand).'%'),
                    '$in' => $q->whereIn($valueColumn, $operand),
                    '$has_any' => $this->containsAny($q, $valueColumn, $operand),
                    default => throw new \LogicException("Unsupported custom field filter operator [{$operator}]."),
                };
            }),
        };
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function hasValue(Builder $query, CustomField $field, string $valueColumn): Builder
    {
        $query->where('custom_field_id', $field->getKey())->whereNotNull($valueColumn);

        return match ($valueColumn) {
            'json_value' => $query->whereRaw("json_value::jsonb not in ('[]'::jsonb, 'null'::jsonb)"),
            'string_value', 'text_value' => $query->where($valueColumn, '<>', ''),
            default => $query,
        };
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $values
     */
    private function containsAny(Builder $query, string $valueColumn, array $values): void
    {
        $query->where(function (Builder $anyValue) use ($valueColumn, $values): void {
            foreach ($values as $value) {
                $anyValue->orWhereJsonContains($valueColumn, [$value]);
            }
        });
    }

    /**
     * @return Collection<string, CustomField>
     */
    private function filterableFields(): Collection
    {
        return resolve(WorkspaceCustomFields::class)
            ->forEntity($this->user->currentWorkspace, $this->entityType)
            ->filter(CustomFieldFilterSchema::isFilterable(...))
            ->keyBy('code');
    }
}
