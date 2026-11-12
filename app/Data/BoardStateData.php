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
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly array $stageOrder = [],
        public readonly array $filters = [],
        public readonly ?string $grouping = null,
    ) {}

    // jsonb reorders object keys, so a stored state only hashes like the live one with keys sorted.
    public function hash(): string
    {
        return hash('xxh128', json_encode(self::sortKeys($this->toArray()), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<string>  $optionIds
     * @return list<string>
     */
    public function orderStages(array $optionIds): array
    {
        return array_values(array_unique([...array_intersect($this->stageOrder, $optionIds), ...$optionIds]));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => is_array($item) ? self::sortKeys($item) : $item, $value);
    }
}
