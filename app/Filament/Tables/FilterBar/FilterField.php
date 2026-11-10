<?php

declare(strict_types=1);

namespace App\Filament\Tables\FilterBar;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;

final readonly class FilterField
{
    /**
     * @param  list<array{id: string, label: string, color: ?string}>  $options
     * @param  list<string>  $operators
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public FilterFieldKind $kind,
        public ?CrmEntity $related = null,
        public array $options = [],
        public array $operators = [],
        public ?CustomFieldType $type = null,
    ) {}

    /** @return list<FilterCondition> */
    public function conditions(): array
    {
        return array_values(array_filter(
            $this->kind->conditions(),
            fn (FilterCondition $condition): bool => array_diff($condition->operators($this->kind), $this->operators) === [],
        ));
    }
}
