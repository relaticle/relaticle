<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class BoardStateData extends Data
{
    /**
     * @param  list<string>  $stageOrder
     */
    public function __construct(
        public readonly array $stageOrder = [],
    ) {}

    /**
     * @param  list<string>  $optionIds  the field's options in settings order
     * @return list<string>
     */
    public function orderStages(array $optionIds): array
    {
        return array_values(array_unique([...array_intersect($this->stageOrder, $optionIds), ...$optionIds]));
    }
}
