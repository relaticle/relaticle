<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterFieldKind;
use App\Enums\FilterKind;
use App\Queries\Filters\AssignedToMeFilter;
use App\Queries\Filters\StaleDaysFilter;
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
     * @param  array<string, mixed>  $computedExample
     */
    private function __construct(
        public FilterKind $kind,
        public ?CrmEntity $related = null,
        public ?string $enumClass = null,
        public ?string $filterClass = null,
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
     * @param  class-string<StaleDaysFilter|AssignedToMeFilter>  $filterClass
     */
    public static function computed(string $filterClass): self
    {
        return new self(FilterKind::Computed, filterClass: $filterClass, computedExample: $filterClass::EXAMPLE, computedOperand: $filterClass::OPERAND);
    }

    public function fieldKind(): ?FilterFieldKind
    {
        return match ($this->kind) {
            FilterKind::Text => FilterFieldKind::Text,
            FilterKind::DateTime => FilterFieldKind::Date,
            FilterKind::Enum => FilterFieldKind::Choice,
            FilterKind::Members => FilterFieldKind::Member,
            FilterKind::Relation => FilterFieldKind::Relation,
            FilterKind::Computed => $this->filterClass === StaleDaysFilter::class ? FilterFieldKind::Inactivity : null,
        };
    }

    /** @return list<string> */
    public function operators(): array
    {
        return match ($this->kind) {
            FilterKind::Text => CustomFieldFilterSchema::operatorKeys(CustomFieldType::TEXT->value),
            FilterKind::DateTime => CustomFieldFilterSchema::operatorKeys(CustomFieldType::DATE_TIME->value),
            FilterKind::Enum => CustomFieldFilterSchema::operatorKeys(CustomFieldType::SELECT->value),
            FilterKind::Members, FilterKind::Relation => self::LINK_OPERATORS,
            FilterKind::Computed => array_keys($this->computedExample),
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
            FilterKind::Enum => ['$in' => array_slice($this->enumValues(), 0, 1)],
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

    /** @return list<string> */
    public function enumValues(): array
    {
        if ($this->enumClass === null) {
            return [];
        }

        return array_map(static fn (BackedEnum $case): string => (string) $case->value, $this->enumClass::cases());
    }
}
