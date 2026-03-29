<?php

declare(strict_types=1);

namespace App\Mcp\Filters;

use App\Models\CustomField;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\CustomFieldValue;
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
        if (! is_array($value) || $value === []) {
            return;
        }

        $fieldCodes = array_keys($value);

        abort_if(count($fieldCodes) > self::MAX_CONDITIONS, 422, 'Maximum 10 filter conditions allowed.');

        $fields = $this->resolveFields($fieldCodes);

        foreach ($value as $fieldCode => $operators) {
            if (! is_array($operators)) {
                continue;
            }
            if (! isset($fields[$fieldCode])) {
                continue;
            }
            $field = $fields[$fieldCode];
            $valueColumn = CustomFieldValue::getValueColumn($field->type);

            foreach ($operators as $operator => $operand) {
                $this->applyCondition($query, $field, $valueColumn, (string) $operator, $operand);
            }
        }
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
                default => null,
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
