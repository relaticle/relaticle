<?php

declare(strict_types=1);

namespace App\Mcp\Filters;

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
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class CustomFieldFilter implements Filter
{
    /** Separator for list operands sent as a single query-string value. */
    private const string LIST_DELIMITER = ',';

    private const int MAX_CONDITIONS = 10;

    private const array OPERATOR_MAP = [
        'eq' => '=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
    ];

    public function __construct(
        private string $entityType,
    ) {}

    /**
     * Spatie splits comma-separated filter values into arrays before the filter runs,
     * which would turn a `contains` term containing a comma into an array. Splitting is
     * disabled here because this filter splits list operands itself, per operator type.
     */
    public static function allowedFilter(string $entityType): AllowedFilter
    {
        return AllowedFilter::custom('custom_fields', new self($entityType))->delimiter('');
    }

    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        if ($value === []) {
            return;
        }

        if (! is_array($value)) {
            $this->invalid('Custom field filters must be an object keyed by field code.');
        }

        $fieldCodes = array_keys($value);

        if (! array_all($fieldCodes, static fn (mixed $fieldCode): bool => is_string($fieldCode))) {
            $this->invalid('Custom field filter codes must be strings.');
        }

        if (count($fieldCodes) > self::MAX_CONDITIONS) {
            $this->invalid('Maximum 10 filter conditions allowed.');
        }

        $fields = $this->filterableFields();

        $unknownFieldCode = array_first(array_diff($fieldCodes, $fields->keys()->all()));

        if ($unknownFieldCode !== null) {
            $this->invalid(__('validation.custom_field.unknown_filter_field', [
                'field' => $unknownFieldCode,
                'entity' => $this->entityType,
                'available' => $fields->isEmpty() ? 'none' : $fields->keys()->implode(', '),
            ]));
        }

        $optionMap = resolve(CustomFieldOptionMap::class);
        $options = $optionMap->fromFields(
            $fields->filter(fn (CustomField $field): bool => in_array($field->code, $fieldCodes, true) && $optionMap->translates($field))->values(),
        );

        foreach ($value as $fieldCode => $operators) {
            if (! is_array($operators) || $operators === []) {
                $this->invalid("Custom field filter [{$fieldCode}] must contain an operator object.");
            }

            $field = $fields[$fieldCode];
            $valueColumn = CustomFieldValue::getValueColumn($field->type);
            $supportedOperators = CustomFieldFilterSchema::operatorsForType($field->type);

            foreach ($operators as $operator => $operand) {
                if (! isset($supportedOperators[$operator])) {
                    $this->invalid(__('validation.custom_field.unsupported_filter_operator', [
                        'operator' => $operator,
                        'field' => $fieldCode,
                        'supported' => implode(', ', array_keys($supportedOperators)),
                    ]));
                }

                $operand = $this->normalizeOperand((string) $fieldCode, $operator, $operand, $supportedOperators[$operator]);
                $operand = $this->resolveOptions($optionMap, (string) $fieldCode, $options[$fieldCode] ?? null, $operand);

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
    private function normalizeOperand(string $fieldCode, string $operator, mixed $operand, array $operatorSchema): mixed
    {
        $type = $operatorSchema['type'] ?? null;

        $normalized = match ($type) {
            'array' => $this->toStringList($operand),
            'boolean' => $this->toBoolean($operand),
            'integer' => $this->toInteger($operand),
            'number' => $this->toNumber($operand),
            'string' => is_string($operand) ? $operand : null,
            default => null,
        };

        if (is_array($normalized) && count($normalized) > CustomFieldFilterSchema::MAX_LIST_VALUES) {
            $this->invalid(__('validation.custom_field.too_many_values', [
                'field' => $fieldCode,
                'max' => CustomFieldFilterSchema::MAX_LIST_VALUES,
            ]));
        }

        if ($normalized !== null) {
            return $normalized;
        }

        $expected = $type === 'array' ? 'an array of strings' : "a {$type}";
        $this->invalid("Custom field filter [{$fieldCode}.{$operator}] must be {$expected}.");
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}|null  $entry
     */
    private function resolveOptions(CustomFieldOptionMap $optionMap, string $fieldCode, ?array $entry, mixed $operand): mixed
    {
        if ($entry === null || is_bool($operand)) {
            return $operand;
        }

        if (is_array($operand)) {
            return array_map(fn (string $value): string => $this->optionId($optionMap, $fieldCode, $entry, $value), $operand);
        }

        return $this->optionId($optionMap, $fieldCode, $entry, (string) $operand);
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function optionId(CustomFieldOptionMap $optionMap, string $fieldCode, array $entry, string $value): string
    {
        $id = $optionMap->idFor($entry, $value);

        if ($id !== null) {
            return $id;
        }

        if ($optionMap->isAmbiguous($entry, $value)) {
            $this->invalid(__('validation.custom_field.ambiguous_option', ['field' => $fieldCode, 'value' => $value]));
        }

        $this->invalid(__('validation.custom_field.unknown_option', [
            'field' => $fieldCode,
            'value' => $value,
            'labels' => $entry['labels'] === [] ? 'none' : implode(', ', $entry['labels']),
        ]));
    }

    /** @return list<string>|null */
    private function toStringList(mixed $operand): ?array
    {
        if (is_string($operand)) {
            $operand = explode(self::LIST_DELIMITER, $operand);
        }

        if (! is_array($operand) || $operand === [] || ! array_is_list($operand)) {
            return null;
        }

        if (! array_all($operand, static fn (mixed $item): bool => is_string($item))) {
            return null;
        }

        return $operand;
    }

    private function toBoolean(mixed $operand): ?bool
    {
        if (is_bool($operand)) {
            return $operand;
        }

        if (! is_string($operand) && ! is_int($operand)) {
            return null;
        }

        return filter_var($operand, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function toInteger(mixed $operand): ?int
    {
        if (is_int($operand)) {
            return $operand;
        }

        if (! is_string($operand)) {
            return null;
        }

        $integer = filter_var($operand, FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
    }

    private function toNumber(mixed $operand): int|float|null
    {
        if (is_int($operand) || is_float($operand)) {
            return $operand;
        }

        if (! is_string($operand)) {
            return null;
        }

        $number = filter_var($operand, FILTER_VALIDATE_FLOAT);

        return $number === false ? null : $number;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['filter' => [$message]]);
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
        $query->whereHas('customFieldValues', function (Builder $q) use ($field, $valueColumn, $operator, $operand): void {
            $q->where('custom_field_id', $field->getKey());

            match ($operator) {
                'eq', 'gt', 'gte', 'lt', 'lte' => $q->where($valueColumn, self::OPERATOR_MAP[$operator], $operand),
                'contains' => $q->where($valueColumn, 'ILIKE', '%'.LikePattern::escape((string) $operand).'%'),
                'in' => $q->whereIn($valueColumn, $operand),
                'has_any' => $this->containsAny($q, $valueColumn, $operand),
                default => throw new \LogicException("Unsupported custom field filter operator [{$operator}]."),
            };
        });
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
        /** @var User $user */
        $user = auth()->user();

        return resolve(WorkspaceCustomFields::class)
            ->forEntity($user->currentWorkspace, $this->entityType)
            ->filter(CustomFieldFilterSchema::isFilterable(...))
            ->keyBy('code');
    }
}
