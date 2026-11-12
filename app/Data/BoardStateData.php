<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\BoardCardSize;
use App\Enums\BoardColumnWidth;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class BoardStateData extends Data
{
    public const string NO_VALUE_COLUMN = '__none__';

    /**
     * @param  list<string>  $stageOrder
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $hiddenColumns
     * @param  list<string>  $noValueColumns
     * @param  list<string>|null  $cardAttributes
     */
    public function __construct(
        public readonly array $stageOrder = [],
        public readonly array $filters = [],
        public readonly ?string $grouping = null,
        public readonly ?string $sort = null,
        public readonly ?string $columnField = null,
        public readonly array $hiddenColumns = [],
        public readonly array $noValueColumns = [],
        public readonly ?array $cardAttributes = null,
        public readonly bool $showLabels = false,
        public readonly bool $hideEmpty = true,
        public readonly BoardColumnWidth $columnWidth = BoardColumnWidth::Wide,
        public readonly BoardCardSize $cardSize = BoardCardSize::Full,
    ) {}

    /**
     * @param  list<string>  $current
     * @param  list<string>  $fieldOptionIds
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function withColumnIds(array $current, array $fieldOptionIds, array $ids): array
    {
        return [...array_values(array_diff($current, $fieldOptionIds)), ...$ids];
    }

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
