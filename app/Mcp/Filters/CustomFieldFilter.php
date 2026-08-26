<?php

declare(strict_types=1);

namespace App\Mcp\Filters;

use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class CustomFieldFilter implements Filter
{
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
                if (! in_array($operator, CustomFieldFilterSchema::operatorNames(), true)) {
                    $this->invalid("Unknown custom field filter operator [{$operator}] for [{$fieldCode}].");
                }

                if (! isset($supportedOperators[$operator])) {
                    $allowed = implode(', ', array_keys($supportedOperators));
                    $this->invalid("Custom field [{$fieldCode}] does not support operator [{$operator}]. Allowed operators: {$allowed}.");
                }

                $this->validateOperand((string) $fieldCode, $operator, $operand, $supportedOperators[$operator]);

                $this->applyCondition($query, $field, $valueColumn, $operator, $operand);
            }
        }
    }

    /** @param array<string, mixed> $operatorSchema */
    private function validateOperand(string $fieldCode, string $operator, mixed $operand, array $operatorSchema): void
    {
        $type = $operatorSchema['type'] ?? null;
        $valid = match ($type) {
            'array' => is_array($operand)
                && $operand !== []
                && array_is_list($operand)
                && array_all($operand, static fn (mixed $item): bool => is_string($item)),
            'boolean' => is_bool($operand),
            'integer' => is_int($operand),
            'number' => is_int($operand) || is_float($operand),
            'string' => is_string($operand),
            default => false,
        };

        if ($valid) {
            return;
        }

        $expected = $type === 'array' ? 'an array of strings' : "a {$type}";
        $this->invalid("Custom field filter [{$fieldCode}.{$operator}] must be {$expected}.");
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
                'contains' => $q->where($valueColumn, 'ILIKE', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $operand).'%'),
                'in' => $q->whereIn($valueColumn, (array) $operand),
                'has_any' => $q->whereJsonContains($valueColumn, $operand),
                default => throw new \LogicException("Unsupported custom field filter operator [{$operator}]."),
            };
        });
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
