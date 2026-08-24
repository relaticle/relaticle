<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Relaticle\Chat\Models\PendingAction;

/**
 * Which records of a batch proposal have been decided.
 *
 * A batch resolves one record at a time, so `result_data.items` is a sparse map
 * of index => outcome and the dock's queue is everything missing from it. That
 * derivation lived in four hand-rolled index loops across the card and the
 * service; it lives here now, including the detail that the map's keys are ints
 * once the JSON column round-trips.
 */
final readonly class ProposalProgress
{
    /**
     * @param  array<array-key, mixed>  $items
     */
    private function __construct(
        private array $items,
        private int $total,
    ) {}

    public static function for(PendingAction $action): self
    {
        $resultData = $action->result_data;
        $items = is_array($resultData) && is_array($resultData['items'] ?? null) ? $resultData['items'] : [];

        return new self($items, ProposalPayload::from($action)->count());
    }

    /**
     * @param  array<array-key, mixed>  $items
     */
    public static function of(array $items, int $total): self
    {
        return new self($items, $total);
    }

    public function isResolved(int $index): bool
    {
        return isset($this->items[$index]);
    }

    /**
     * The outcome already stored for a record, never an assumed one: a caller
     * re-approving an item that was rejected must be told it was rejected.
     */
    public function statusOf(int $index, string $default): string
    {
        $item = $this->items[$index] ?? null;

        return is_array($item) ? (string) ($item['status'] ?? $default) : $default;
    }

    /**
     * Record indices still awaiting a decision: the only items the dock
     * presents, so the stepper can never land back on a decided one.
     *
     * @return list<int>
     */
    public function unresolvedIndices(): array
    {
        if ($this->total < 1) {
            return [];
        }

        return array_values(array_filter(
            range(0, $this->total - 1),
            fn (int $index): bool => ! $this->isResolved($index),
        ));
    }

    public function firstUnresolvedIndex(): int
    {
        return $this->unresolvedIndices()[0] ?? max(0, $this->total - 1);
    }

    public function isComplete(): bool
    {
        return count($this->items) >= $this->total;
    }
}
