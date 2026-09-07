<?php

declare(strict_types=1);

namespace App\Mcp\Filters;

use App\Enums\CrmEntity;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Models\CustomFieldRelationship;
use Relaticle\CustomFields\QueryBuilders\RecordLinkQuery;
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

        $fields = $this->resolveFields($fieldCodes);

        $unknownFieldCodes = array_diff($fieldCodes, $fields->keys()->all());

        if ($unknownFieldCodes !== []) {
            $this->invalid('Unknown custom field filter codes: '.implode(', ', $unknownFieldCodes).'.');
        }

        foreach ($value as $fieldCode => $operators) {
            if (! is_array($operators) || $operators === []) {
                $this->invalid("Custom field filter [{$fieldCode}] must contain an operator object.");
            }

            $field = $fields[$fieldCode];
            $valueColumn = CustomFieldValue::getValueColumn($field->type);
            $supportedOperators = CustomFieldFilterSchema::operatorsForType($field->type);

            foreach ($operators as $operator => $operand) {
                if (! isset($supportedOperators[$operator])) {
                    $allowed = implode(', ', array_keys($supportedOperators));
                    $this->invalid("Custom field [{$fieldCode}] does not support operator [{$operator}]. Allowed operators: {$allowed}.");
                }

                $operand = $this->normalizeOperand((string) $fieldCode, $operator, $operand, $supportedOperators[$operator]);

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

        if ($normalized !== null) {
            return $normalized;
        }

        $expected = $type === 'array' ? 'an array of strings' : "a {$type}";
        $this->invalid("Custom field filter [{$fieldCode}.{$operator}] must be {$expected}.");
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
        $definition = $field->relationshipDefinition();

        if ($definition instanceof CustomFieldRelationship) {
            $this->applyLinkCondition($query, $field, $definition, $operator, $operand);

            return;
        }

        $query->whereHas('customFieldValues', function (Builder $q) use ($field, $valueColumn, $operator, $operand): void {
            $q->where('custom_field_id', $field->getKey());

            match ($operator) {
                'eq', 'gt', 'gte', 'lt', 'lte' => $q->where($valueColumn, self::OPERATOR_MAP[$operator], $operand),
                'contains' => $q->where($valueColumn, 'ILIKE', '%'.LikePattern::escape((string) $operand).'%'),
                'in' => $q->whereIn($valueColumn, $operand),
                'has_any' => $q->whereJsonContains($valueColumn, $operand),
                default => throw new \LogicException("Unsupported custom field filter operator [{$operator}]."),
            };
        });
    }

    /**
     * A link field holds no value row: its condition rides the edge ledger. `eq` and `in`
     * name the linked records by id, `contains` matches their own searchable attributes,
     * so a caller can ask for the people linked to "Acme" without looking the id up.
     *
     * @param  Builder<Model>  $query
     */
    private function applyLinkCondition(
        Builder $query,
        CustomField $field,
        CustomFieldRelationship $definition,
        string $operator,
        mixed $operand,
    ): void {
        $links = resolve(RecordLinkQuery::class);
        $direction = $definition->readDirectionFor($field);

        if ($operator === 'contains') {
            $links->whereLinkedMatching($query, $definition, $direction, $this->targetSearchAttributes($definition, $field), (string) $operand);

            return;
        }

        $links->whereLinkedTo($query, $definition, $direction, is_array($operand) ? $operand : [$operand]);
    }

    /**
     * What "contains" reads on the far end: the record's own name, which is what the
     * caller typed and what every surface shows.
     *
     * @return array<int, string>
     */
    private function targetSearchAttributes(CustomFieldRelationship $definition, CustomField $field): array
    {
        $entityType = $definition->targetEntityTypeFor($field);

        return [CrmEntity::tryFrom($entityType)?->titleColumn() ?? 'name'];
    }

    /**
     * @param  array<int, string>  $fieldCodes
     * @return Collection<string, CustomField>
     */
    private function resolveFields(array $fieldCodes): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        /** @var Collection<string, CustomField> */
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $user->currentTeam->getKey())
            ->where('entity_type', $this->entityType)
            ->whereIn('code', $fieldCodes)
            ->active()
            ->get()
            ->keyBy('code');
    }
}
