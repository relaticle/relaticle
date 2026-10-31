<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class TableStateData extends Data
{
    /**
     * @param  list<array{type?: string, name?: string, isToggled?: bool}>  $columns
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $columnSearches
     * @param  array<string, int>  $widths
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public array $columns = [],
        public ?string $sort = null,
        public array $filters = [],
        public ?string $search = null,
        public array $columnSearches = [],
        public ?string $grouping = null,
        public int|string|null $perPage = null,
        public array $widths = [],
        public array $labels = [],
    ) {}

    public function withoutWidths(): self
    {
        $state = clone $this;
        $state->widths = [];

        return $state;
    }

    // jsonb reorders object keys, so a stored state only hashes like the live one with keys sorted.
    public function hash(): string
    {
        return hash('xxh128', json_encode(self::sortKeys($this->toArray()), JSON_THROW_ON_ERROR));
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
