<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterKind;
use App\Mcp\Schema\CustomFieldFilterSchema;
use BackedEnum;
use Spatie\QueryBuilder\Filters\Filter;

final readonly class FilterDefinition
{
    public const array LINK_OPERATORS = ['$in', '$not_in', '$is_empty'];

    public const string RELATION_OPERAND = '$in, $not_in or $is_empty on record ids, or conditions on the related record that use that record type\'s own filter names, including custom_fields. Prefer conditions over listing the related records first and passing their ids';

    public const string MEMBER_OPERAND = '$in, $not_in or $is_empty on workspace member ids only, with no nested conditions';

    public const string SAMPLE_ID = '01J8Z4Y6T5Q2M9N3B7K1W0X8VD';

    /**
     * @param  class-string<BackedEnum>|null  $enumClass
     * @param  class-string<Filter<*>>|null  $filterClass
     * @param  list<string>  $computedOperators
     * @param  array<string, mixed>  $computedExample
     */
    private function __construct(
        public FilterKind $kind,
        public ?CrmEntity $related = null,
        public ?string $enumClass = null,
        public ?string $filterClass = null,
        private array $computedOperators = [],
        private array $computedExample = [],
        private ?string $computedOperand = null,
    ) {}

    public static function text(): self
    {
        return new self(FilterKind::Text);
    }

    public static function dateTime(): self
    {
        return new self(FilterKind::DateTime);
    }

    /** @param class-string<BackedEnum> $enumClass */
    public static function enum(string $enumClass): self
    {
        return new self(FilterKind::Enum, enumClass: $enumClass);
    }

    public static function members(): self
    {
        return new self(FilterKind::Members);
    }

    public static function relation(CrmEntity $related): self
    {
        return new self(FilterKind::Relation, related: $related);
    }

    /**
     * @param  class-string<Filter<*>>  $filterClass
     * @param  list<string>  $operators
     * @param  array<string, mixed>  $example
     */
    public static function computed(string $filterClass, array $operators, array $example, string $operand): self
    {
        return new self(FilterKind::Computed, filterClass: $filterClass, computedOperators: $operators, computedExample: $example, computedOperand: $operand);
    }

    /** @return list<string> */
    public function operators(): array
    {
        return match ($this->kind) {
            FilterKind::Text => array_keys(CustomFieldFilterSchema::operatorsForType(CustomFieldType::TEXT->value)),
            FilterKind::DateTime => array_keys(CustomFieldFilterSchema::operatorsForType(CustomFieldType::DATE_TIME->value)),
            FilterKind::Enum => array_keys(CustomFieldFilterSchema::operatorsForType(CustomFieldType::SELECT->value)),
            FilterKind::Members, FilterKind::Relation => self::LINK_OPERATORS,
            FilterKind::Computed => $this->computedOperators,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function example(): array
    {
        return match ($this->kind) {
            FilterKind::Text => ['$contains' => 'Acme'],
            FilterKind::DateTime => ['$gte' => '2026-01-01'],
            FilterKind::Enum => ['$in' => $this->firstEnumValue()],
            FilterKind::Members, FilterKind::Relation => ['$in' => [self::SAMPLE_ID]],
            FilterKind::Computed => $this->computedExample,
        };
    }

    public function operand(): ?string
    {
        return match ($this->kind) {
            FilterKind::Members => self::MEMBER_OPERAND,
            FilterKind::Relation => self::RELATION_OPERAND,
            FilterKind::Computed => $this->computedOperand,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function firstEnumValue(): array
    {
        $case = $this->enumClass === null ? null : array_first($this->enumClass::cases());

        return $case === null ? [] : [(string) $case->value];
    }
}
