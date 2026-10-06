<?php

declare(strict_types=1);

namespace App\Queries\Filters;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\FilterErrors;
use App\Queries\Operand;
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
    // The link field type validates each item with max:2048, so a longer operand can never equal a stored value.
    private const int MAX_OPERAND_LENGTH = 2048;

    private const string LIST_ELEMENTS = "jsonb_array_elements_text(case when jsonb_typeof(json_value::jsonb) = 'array' then json_value::jsonb else '[]'::jsonb end)";

    private const string LOWERED_OPERANDS = 'array(select lower(operand) from unnest(?::text[]) as operand)';

    private const array DOMAIN_OF = [
        CustomFieldType::EMAIL->value => "lower(split_part(element, '@', 2))",
        CustomFieldType::LINK->value => "rtrim(regexp_replace(split_part(regexp_replace(split_part(split_part(split_part(regexp_replace(regexp_replace(lower(element), '[\\s\\u00A0\\u200B\\uFEFF\\u3000]+', '', 'g'), '^[a-z][a-z0-9+.-]*://', ''), '/', 1), '?', 1), '#', 1), '^.*@', ''), ':', 1), '^(www\\.)+', ''), '.')",
    ];

    public function __construct(
        private string $entityType,
        private User $user,
        private ?string $viewerZone,
        private CustomFieldOptionMap $optionMap = new CustomFieldOptionMap,
    ) {}

    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        if ($value === []) {
            return;
        }

        if (! is_array($value)) {
            throw FilterErrors::at('', __('validation.custom_field.filter_not_object'));
        }

        $fieldCodes = array_keys($value);

        if (! array_all($fieldCodes, static fn (mixed $fieldCode): bool => is_string($fieldCode))) {
            throw FilterErrors::at('', __('validation.custom_field.filter_code_not_string'));
        }

        $fields = $this->filterableFields();

        $unknownFieldCode = array_first(array_diff($fieldCodes, $fields->keys()->all()));

        if ($unknownFieldCode !== null) {
            throw FilterErrors::at((string) $unknownFieldCode, __('validation.custom_field.unknown_filter_field', [
                'field' => $unknownFieldCode,
                'entity' => $this->entityType,
                'available' => $fields->isEmpty() ? 'none' : $fields->keys()->implode(', '),
            ]));
        }

        $options = $this->optionMap->fromFields(
            $fields->filter(fn (CustomField $field): bool => in_array($field->code, $fieldCodes, true) && $this->optionMap->translates($field))->values(),
        );

        foreach ($value as $fieldCode => $operators) {
            $field = $fields[$fieldCode];
            $valueColumn = $field->getValueColumn();
            $supportedOperators = CustomFieldFilterSchema::operatorsForType($field->type);

            if (! is_array($operators) || $operators === []) {
                throw FilterErrors::at((string) $fieldCode, __('validation.filter.operator_object', ['name' => $fieldCode, 'operator' => array_key_first($supportedOperators)]));
            }

            $entry = $options[$fieldCode] ?? null;

            foreach ($operators as $operator => $operand) {
                $this->assertSupported($fieldCode, (string) $operator, $supportedOperators);

                if ($operator === 'domain') {
                    $this->applyDomain($query, $field, $operand, $supportedOperators['domain']['properties']);

                    continue;
                }

                $operand = $this->normalizeOperand($fieldCode, $operator, $operand, $supportedOperators[$operator], $entry !== null);
                $operand = $this->resolveOptions($fieldCode, $operator, $entry, $operand);
                $operand = $this->spellings($field, $operator, $operand);

                $this->applyCondition($query, $field, $valueColumn, $operator, $operand);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $operatorSchema
     */
    private function normalizeOperand(string $fieldCode, string $operator, mixed $operand, array $operatorSchema, bool $splitsStrings): mixed
    {
        $type = $operatorSchema['type'] ?? null;

        if (($operatorSchema['format'] ?? null) === 'date-time') {
            Operand::assertOffset("{$fieldCode}.{$operator}", "{$fieldCode} {$operator}", $operand, $this->viewerZone);
        }

        $normalized = match ($type) {
            'array' => Operand::stringList($operand, $splitsStrings),
            'boolean' => Operand::boolean($operand),
            'integer' => Operand::integer($operand),
            'number' => Operand::number($operand),
            'string' => $this->formattedString($operand, $operatorSchema['format'] ?? null),
            default => null,
        };

        if (is_array($normalized) && count($normalized) > CustomFieldFilterSchema::MAX_LIST_VALUES) {
            throw FilterErrors::at("{$fieldCode}.{$operator}", __('validation.filter.too_many_values', [
                'name' => "{$fieldCode} {$operator}",
                'max' => CustomFieldFilterSchema::MAX_LIST_VALUES,
            ]));
        }

        if ($normalized !== null) {
            return $normalized;
        }

        $expected = match (true) {
            $type === 'array' => __('validation.custom_field.expected.string_list'),
            $type === 'integer' => __('validation.custom_field.expected.integer'),
            isset($operatorSchema['format']) => __('validation.filter.expected.date'),
            $type === 'string' => __('validation.filter.expected.string'),
            default => __('validation.custom_field.expected.type', ['type' => $type]),
        };
        throw FilterErrors::at("{$fieldCode}.{$operator}", __('validation.filter.operand_type', [
            'name' => "{$fieldCode} {$operator}",
            'expected' => $expected,
        ]));
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}|null  $entry
     */
    private function resolveOptions(string $fieldCode, string $operator, ?array $entry, mixed $operand): mixed
    {
        if ($entry === null || is_bool($operand)) {
            return $operand;
        }

        if (is_array($operand)) {
            return array_map(fn (string $value): string => $this->optionId($fieldCode, $operator, $entry, $value), $operand);
        }

        return $this->optionId($fieldCode, $operator, $entry, (string) $operand);
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    private function optionId(string $fieldCode, string $operator, array $entry, string $value): string
    {
        $id = $this->optionMap->idFor($entry, $value);

        if ($id !== null) {
            return $id;
        }

        if ($this->optionMap->isAmbiguous($entry, $value)) {
            throw FilterErrors::at("{$fieldCode}.{$operator}", __('validation.custom_field.ambiguous_option', ['field' => $fieldCode, 'value' => $value]));
        }

        throw FilterErrors::at("{$fieldCode}.{$operator}", __('validation.custom_field.unknown_option', [
            'field' => $fieldCode,
            'value' => $value,
            'labels' => $entry['labels'] === [] ? 'none' : implode(', ', $entry['labels']),
        ]));
    }

    private function formattedString(mixed $operand, ?string $format): ?string
    {
        return match ($format) {
            'date' => Operand::date($operand)?->toDateString(),
            'date-time' => is_string($operand) && Operand::isBareDate($operand) ? Operand::date($operand)?->toDateString() : Operand::date($operand)?->utc()->toDateTimeString(),
            default => Operand::string($operand),
        };
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
            '$has_none' => $query->whereDoesntHave('customFieldValues', function (Builder $q) use ($field, $operand): void {
                $q->where('custom_field_id', $field->getKey());
                $this->containsAny($q, $field, $operand);
            }),
            '$is_empty' => $this->emptiness($query, $field, $operand === true),
            default => $query->whereHas('customFieldValues', function (Builder $q) use ($field, $valueColumn, $operator, $operand): void {
                $q->where('custom_field_id', $field->getKey());

                match ($operator) {
                    '$eq', '$gt', '$gte', '$lt', '$lte' => $this->coversTheWholeDay($field, $operand)
                        ? $this->wholeDay($q, $valueColumn, $operator, $operand)
                        : $q->where($valueColumn, CustomFieldFilterSchema::COMPARISONS[$operator], $operand),
                    '$contains' => $q->where($valueColumn, 'ILIKE', '%'.LikePattern::escape((string) $operand).'%'),
                    '$in' => $q->whereIn($valueColumn, $operand),
                    '$has_any' => $this->containsAny($q, $field, $operand),
                    default => throw new \LogicException("Unsupported custom field filter operator [{$operator}]."),
                };
            }),
        };
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function wholeDay(Builder $query, string $valueColumn, string $operator, string $date): void
    {
        foreach (Operand::wholeDay($operator, $date, $this->viewerZone ?? 'UTC') as [$comparison, $instant]) {
            $query->where($valueColumn, $comparison, $instant);
        }
    }

    private function coversTheWholeDay(CustomField $field, mixed $operand): bool
    {
        return $field->type === CustomFieldType::DATE_TIME->value && is_string($operand) && Operand::isBareDate($operand);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function emptiness(Builder $query, CustomField $field, bool $empty): void
    {
        $holdingAValue = static function (Builder $values) use ($field): void {
            /** @var Builder<CustomFieldValue> $values */
            $values->holdingAValue($field);
        };

        $empty
            ? $query->whereDoesntHave('customFieldValues', $holdingAValue)
            : $query->whereHas('customFieldValues', $holdingAValue);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $values
     */
    private function containsAny(Builder $query, CustomField $field, array $values): void
    {
        $matches = in_array($field->type, [CustomFieldType::EMAIL->value, CustomFieldType::LINK->value, CustomFieldType::DOMAIN->value], true)
            ? 'lower(element) = any('.self::LOWERED_OPERANDS.')'
            : 'element = any(?::text[])';

        $query->whereRaw('exists (select 1 from '.self::LIST_ELEMENTS." as element where {$matches})", [$this->textArray($values)]);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, array<string, mixed>>  $domainOperators
     */
    private function applyDomain(Builder $query, CustomField $field, mixed $operators, array $domainOperators): void
    {
        $path = "{$field->code}.domain";

        if (! is_array($operators) || $operators === [] || array_is_list($operators)) {
            throw FilterErrors::at($path, __('validation.filter.operator_object', ['name' => 'domain', 'operator' => '$in']));
        }

        $expression = self::DOMAIN_OF[$field->type] ?? throw new \LogicException("Field type [{$field->type}] has no domain.");

        foreach ($operators as $operator => $operand) {
            $operator = (string) $operator;

            $this->assertSupported($path, $operator, $domainOperators);

            $domains = array_map(
                trim(...),
                $this->normalizeOperand($path, $operator, $operand, $domainOperators[$operator], true),
            );

            $this->assertWithinLength("{$path}.{$operator}", $domains);

            if ($field->type === CustomFieldType::LINK->value) {
                $domains = array_map(static fn (string $domain): string => rtrim((string) preg_replace('/^(www\.)+/i', '', $domain), '.'), $domains);
            }

            if (array_any($domains, static fn (string $domain): bool => preg_match('/^[^\s\p{Cc}\/@:?#]+$/u', $domain) !== 1)) {
                throw FilterErrors::at("{$path}.{$operator}", __('validation.filter.operand_type', [
                    'name' => "{$path} {$operator}",
                    'expected' => __('validation.custom_field.expected.domains'),
                ]));
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
        if (isset($supported['$'.$operator])) {
            throw FilterErrors::sigil("{$path}.{$operator}", $operator);
        }

        if (! isset($supported[$operator])) {
            throw FilterErrors::at("{$path}.{$operator}", __('validation.filter.unsupported_operator', [
                'name' => $path,
                'operator' => $operator,
                'supported' => implode(', ', array_keys($supported)),
            ]));
        }
    }

    private function spellings(CustomField $field, string $operator, mixed $operand): mixed
    {
        if (! is_array($operand) || ! in_array($operator, ['$has_any', '$has_none'], true) || ! in_array($field->type, [CustomFieldType::EMAIL->value, CustomFieldType::LINK->value, CustomFieldType::DOMAIN->value, CustomFieldType::PHONE->value], true)) {
            return $operand;
        }

        $this->assertWithinLength("{$field->code}.{$operator}", $operand);

        $spellings = [];

        foreach ($operand as $index => $value) {
            if ($field->type === CustomFieldType::PHONE->value) {
                $this->assertPhone($field, "{$field->code}.{$operator}.{$index}", $value);
            }

            array_push($spellings, ...CanonicalValue::spellings($field, $value));
        }

        return array_values(array_unique($spellings));
    }

    private function assertPhone(CustomField $field, string $path, string $value): void
    {
        $canonical = CanonicalValue::of($field, $value);

        if (! str_starts_with($canonical, '+')) {
            throw FilterErrors::at($path, __('validation.filter.phone_country_code', ['name' => $field->code]));
        }

        if (preg_match('/^\+\d{1,15}(;ext=\d+)?$/', $canonical) !== 1) {
            throw FilterErrors::at($path, __('validation.filter.phone_invalid', ['name' => $field->code, 'value' => $value]));
        }
    }

    /**
     * @param  array<int, string>  $values
     */
    private function assertWithinLength(string $path, array $values): void
    {
        $index = array_find_key($values, static fn (string $value): bool => mb_strlen($value) > self::MAX_OPERAND_LENGTH);

        if ($index !== null) {
            throw FilterErrors::at("{$path}.{$index}", __('validation.custom_field.operand_too_long', ['field' => $path, 'max' => self::MAX_OPERAND_LENGTH]));
        }
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
        $workspace = $this->user->currentWorkspace;
        $customFields = resolve(WorkspaceCustomFields::class);

        return collect($customFields->remember(
            $workspace,
            "filterable_fields:{$this->entityType}",
            fn (): array => $customFields->forEntity($workspace, $this->entityType)
                ->filter(CustomFieldFilterSchema::isFilterable(...))
                ->keyBy('code')
                ->all(),
        ));
    }
}
