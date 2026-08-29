<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Relaticle\Chat\Models\PendingAction;
use RuntimeException;

/**
 * The one decoder for a proposal's stored payload.
 *
 * `pending_actions.action_data` is a four-arm union encoded as marker keys: a
 * single create is the record itself, a single update adds `_record_id` and
 * `_model_class`, a single delete is `_record_ids`, and any multi-record
 * proposal is `_batch` + `records`. Every reader used to re-derive that union
 * inline, which let them disagree on the same row: the dock counted a malformed
 * batch as one record while approval refused it outright.
 *
 * Reading is deliberately split by consequence. `recordAtOrEmpty()` is lenient
 * because it feeds a render, where a throw would blank the dock. `batchRecords()`
 * and `batchRecordAt()` are strict because they feed a real CRM write, where a
 * silently emptied record would create a blank one.
 *
 * The stored shape is frozen: replayed proposal tool results embed action_data
 * verbatim (rewriting one invalidates the Anthropic prompt-cache prefix), and
 * PendingActionService's retry idempotency compares action_data by strict array
 * equality. This class only ever reads.
 */
final readonly class ProposalPayload
{
    /**
     * @param  list<mixed>  $records  raw: a malformed entry stays detectable at the point of use
     * @param  list<array<string, mixed>>  $displays
     * @param  array<string, mixed>  $actionData
     */
    private function __construct(
        public bool $isBatch,
        private array $records,
        private array $displays,
        private array $actionData,
    ) {}

    public static function from(PendingAction $action): self
    {
        return self::of($action->action_data, $action->display_data);
    }

    /**
     * @param  array<string, mixed>  $actionData
     * @param  array<string, mixed>  $displayData
     */
    public static function of(array $actionData, array $displayData): self
    {
        if (($actionData['_batch'] ?? false) !== true) {
            return new self(false, [$actionData], [$displayData], $actionData);
        }

        $records = is_array($actionData['records'] ?? null) ? array_values($actionData['records']) : [];
        $items = is_array($displayData['items'] ?? null) ? array_values($displayData['items']) : [];

        /** @var list<array<string, mixed>> $displays */
        $displays = array_map(static fn (mixed $item): array => is_array($item) ? $item : [], $items);

        return new self(true, $records, $displays, $actionData);
    }

    /**
     * How many records the proposal covers. Never zero: a proposal the dock
     * cannot describe still has to render as one record rather than none.
     */
    public function count(): int
    {
        return max(1, count($this->records));
    }

    /**
     * The record at $index for rendering. Never throws, so a payload the dock
     * cannot read degrades to an empty card instead of a broken page.
     *
     * @return array<string, mixed>
     */
    public function recordAtOrEmpty(int $index): array
    {
        $record = $this->records[$index] ?? null;

        return is_array($record) ? $record : [];
    }

    /**
     * The display rows at $index, falling back to the first item so a cursor
     * that outran a shorter display list still renders something truthful.
     *
     * @return array<string, mixed>
     */
    public function displayAt(int $index): array
    {
        return $this->displays[$index] ?? $this->displays[0] ?? [];
    }

    /**
     * One (data, display) pair per record the proposal covers.
     *
     * @return list<array{data: array<string, mixed>, display: array<string, mixed>}>
     */
    public function items(): array
    {
        $count = max(count($this->records), count($this->displays));

        if ($count === 0) {
            return [];
        }

        return array_map(fn (int $index): array => [
            'data' => $this->recordAtOrEmpty($index),
            'display' => $this->displays[$index] ?? [],
        ], range(0, $count - 1));
    }

    /**
     * The records of a batch proposal, refusing every shape that cannot be
     * executed item by item.
     *
     * @return list<array<string, mixed>>
     */
    public function batchRecords(): array
    {
        throw_unless($this->isBatch, RuntimeException::class, 'Per-item resolution applies only to batch proposals');

        throw_if($this->records === [], RuntimeException::class, 'Missing or invalid records in batch action data');

        $records = [];

        foreach ($this->records as $record) {
            throw_unless(is_array($record), RuntimeException::class, 'Batch record data is malformed');

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    public function batchRecordAt(int $index): array
    {
        $records = $this->batchRecords();

        throw_if($index < 0 || $index >= count($records), RuntimeException::class, 'Item index out of range');

        return $records[$index];
    }

    /**
     * The ids a single-record delete targets. A batch delete carries one
     * `_record_id` per item instead and resolves through batchRecords().
     *
     * @return list<string>
     */
    public function recordIds(): array
    {
        $ids = $this->actionData['_record_ids'] ?? null;

        throw_if(! is_array($ids) || $ids === [], RuntimeException::class, 'Missing or invalid _record_ids in action data');

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
    }

    /**
     * The record id carried by one record of an update or delete payload.
     *
     * @param  array<string, mixed>  $record
     */
    public static function recordIdOf(array $record, string $context = 'action data'): string
    {
        $recordId = $record['_record_id'] ?? null;

        throw_if(! is_string($recordId) && ! is_int($recordId), RuntimeException::class, "Missing or invalid _record_id in {$context}");

        return (string) $recordId;
    }

    /**
     * A record with the routing markers removed, leaving only the fields an
     * action is allowed to write.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function withoutMarkers(array $record): array
    {
        return array_diff_key($record, array_flip(['_record_id', '_model_class']));
    }
}
