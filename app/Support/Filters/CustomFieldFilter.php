<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Support\CustomFields\CanonicalValue;
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
    private const array OPERATOR_MAP = [
        '$eq' => '=',
        '$gt' => '>',
        '$gte' => '>=',
        '$lt' => '<',
        '$lte' => '<=',
    ];

    private const string LIST_ELEMENTS = "jsonb_array_elements_text(case when jsonb_typeof(json_value::jsonb) = 'array' then json_value::jsonb else '[]'::jsonb end)";

    private const string LOWERED_OPERANDS = 'array(select lower(operand) from unnest(?::text[]) as operand)';

    private const array DOMAIN_OF = [
        'email' => "lower(split_part(element, '@', 2))",
        'link' => "rtrim(regexp_replace(split_part(regexp_replace(split_part(split_part(split_part(regexp_replace(regexp_replace(lower(element), '[\\s\\u00A0\\u200B\\uFEFF\\u3000]+', '', 'g'), '^[a-z][a-z0-9+.-]*://', ''), '/', 1), '?', 1), '#', 1), '^.*@', ''), ':', 1), '^(www\\.)+', ''), '.')",
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
                $this->assertSupported($fieldCode, (string) $operator, $supportedOperators);

                if ($operator === 'domain') {
                    $this->applyDomain($query, $field, $operand, $supportedOperators['domain']['properties']);

                    continue;
                }

                $operand = $this->normalizeOperand($fieldCode, $operator, $operand, $supportedOperators[$operator], $entry !== null);
                $operand = $this->resolveOptions($optionMap, $fieldCode, $operator, $entry, $operand);
                $operand = $this->spellings($field, $operator, $operand);

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
                $this->containsAny($q, $field, $valueColumn, $operand);
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
                    '$has_any' => $this->containsAny($q, $field, $valueColumn, $operand),
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
    private function containsAny(Builder $query, CustomField $field, string $valueColumn, array $values): void
    {
        if (in_array($field->type, ['email', 'link'], true)) {
            $query->whereRaw(
                'exists (select 1 from '.self::LIST_ELEMENTS.' as element where lower(element) = any('.self::LOWERED_OPERANDS.'))',
                [$this->textArray($values)],
            );

            return;
        }

        $query->where(function (Builder $anyValue) use ($valueColumn, $values): void {
            foreach ($values as $value) {
                $anyValue->orWhereJsonContains($valueColumn, [$value]);
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, array<string, mixed>>  $domainOperators
     */
    private function applyDomain(Builder $query, CustomField $field, mixed $operators, array $domainOperators): void
    {
        $path = "{$field->code}.domain";

        if (! is_array($operators) || $operators === [] || array_is_list($operators)) {
            $this->invalid(__('validation.filter.operator_object', ['name' => 'domain', 'operator' => '$in']), $path);
        }

        $expression = self::DOMAIN_OF[$field->type] ?? throw new \LogicException("Field type [{$field->type}] has no domain.");

        foreach ($operators as $operator => $operand) {
            $operator = (string) $operator;

            $this->assertSupported($path, $operator, $domainOperators);

            $domains = array_map(
                static fn (string $domain): string => trim($domain),
                $this->normalizeOperand($path, $operator, $operand, $domainOperators[$operator], true),
            );

            if ($field->type === 'link') {
                $domains = array_map(static fn (string $domain): string => rtrim((string) preg_replace('/^(www\.)+/i', '', $domain), '.'), $domains);
            }

            if (array_any($domains, static fn (string $domain): bool => preg_match('/^[^\s\/@:?#]+$/u', $domain) !== 1)) {
                $this->invalid(__('validation.custom_field.operand_type', [
                    'field' => $path,
                    'operator' => $operator,
                    'expected' => 'a list of domains such as acme.com',
                ]), "{$path}.{$operator}");
            }

            $matching = fn (Builder $values): Builder => $values
                ->where('custom_field_id', $field->getKey())
                ->whereRaw('exists (select 1 from '.self::LIST_ELEMENTS." as element where {$expression} = any(".self::LOWERED_OPERANDS.'))', [$this->textArray($domains)]);

            match ($operator) {
                '$in' => $query->whereHas('customFieldValues', $matching),
                '$not_in' => $query->whereDoesntHave('customFieldValues', $matching),
                default => throw new \LogicException("Unsupported domain operator [{$operator}]."),
            };
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $supported
     */
    private function assertSupported(string $path, string $operator, array $supported): void
    {
        if (! str_starts_with($operator, '$') && isset($supported['$'.$operator])) {
            $this->invalid(__('validation.filter.operator_sigil', ['operator' => '$'.$operator]), "{$path}.{$operator}");
        }

        if (! isset($supported[$operator])) {
            $this->invalid(__('validation.custom_field.unsupported_filter_operator', [
                'operator' => $operator,
                'field' => $path,
                'supported' => implode(', ', array_keys($supported)),
            ]), "{$path}.{$operator}");
        }
    }

    private function spellings(CustomField $field, string $operator, mixed $operand): mixed
    {
        if (! is_array($operand) || ! in_array($operator, ['$has_any', '$has_none'], true) || ! in_array($field->type, ['email', 'link', 'phone'], true)) {
            return $operand;
        }

        $spellings = [];

        foreach ($operand as $index => $value) {
            $canonical = CanonicalValue::of($field, $value);

            if ($field->type === 'phone' && ! str_starts_with($canonical, '+')) {
                $this->invalid(__('validation.filter.phone_country_code', ['name' => $field->code]), "{$field->code}.{$operator}.{$index}");
            }

            if ($field->type === 'phone' && preg_match('/^\+\d{1,15}(;ext=\d+)?$/', $canonical) !== 1) {
                $this->invalid(__('validation.filter.phone_invalid', ['name' => $field->code, 'value' => $value]), "{$field->code}.{$operator}.{$index}");
            }

            $spellings[] = $canonical;
            $spellings[] = $value;
        }

        return array_values(array_unique($spellings));
    }

    /**
     * @param  array<int, string>  $values
     */
    private function textArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $value): string => '"'.addcslashes($value, '"\\').'"', $values)).'}';
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
