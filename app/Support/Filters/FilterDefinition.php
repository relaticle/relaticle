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

    /**
     * @param  class-string<BackedEnum>|null  $enumClass
     * @param  class-string<Filter<*>>|null  $filterClass
     * @param  list<string>  $computedOperators
     */
    private function __construct(
        public FilterKind $kind,
        public ?CrmEntity $related = null,
        public ?string $enumClass = null,
        public ?string $filterClass = null,
        private array $computedOperators = [],
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
     */
    public static function computed(string $filterClass, array $operators): self
    {
        return new self(FilterKind::Computed, filterClass: $filterClass, computedOperators: $operators);
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
}
