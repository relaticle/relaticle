<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
use App\Models\CustomField;
use App\Support\CustomFields\CustomFieldOptionMap;

final readonly class FilterField
{
    /**
     * @param  list<array{id: string, label: string, color: ?string}>  $options
     * @param  list<string>  $operators
     */
    public function __construct(
        public string $key,
        public CrmEntity $entity,
        public string $label,
        public string $icon,
        public FilterFieldKind $kind,
        public ?CrmEntity $related = null,
        public array $options = [],
        public array $operators = [],
        public ?CustomFieldType $type = null,
        public ?CustomField $definition = null,
    ) {}

    public function picksFromOptions(): bool
    {
        return $this->definition instanceof CustomField
            ? (new CustomFieldOptionMap)->translates($this->definition)
            : $this->kind === FilterFieldKind::Choice && $this->options !== [];
    }

    /** @return list<FilterCondition> */
    public function conditions(): array
    {
        return array_values(array_filter(
            $this->kind->conditions(),
            fn (FilterCondition $condition): bool => array_diff($condition->operators($this->kind), $this->operators) === [],
        ));
    }
}
