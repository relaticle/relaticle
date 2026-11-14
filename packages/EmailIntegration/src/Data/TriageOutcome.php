<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Relaticle\EmailIntegration\Enums\TriageSkipReason;

final readonly class TriageOutcome
{
    /**
     * @param  list<string>  $appliedKeys
     * @param  array<value-of<TriageSkipReason>, int>  $skipped
     */
    public function __construct(
        public array $appliedKeys,
        public array $skipped = [],
    ) {}

    /** @param  list<string>  $keys */
    public function skipping(TriageSkipReason $reason, array $keys): self
    {
        $skipped = array_values(array_intersect($this->appliedKeys, $keys));

        if ($skipped === []) {
            return $this;
        }

        return new self(
            array_values(array_diff($this->appliedKeys, $skipped)),
            [...$this->skipped, $reason->value => ($this->skipped[$reason->value] ?? 0) + count($skipped)],
        );
    }

    public function skippedCount(TriageSkipReason $reason): int
    {
        return $this->skipped[$reason->value] ?? 0;
    }

    /** @return array{applied: list<string>, skipped: list<array{reason: string, label: string, count: int}>} */
    public function toArray(): array
    {
        $skipped = [];

        foreach (TriageSkipReason::cases() as $reason) {
            if ($this->skippedCount($reason) > 0) {
                $skipped[] = ['reason' => $reason->value, 'label' => $reason->label(), 'count' => $this->skippedCount($reason)];
            }
        }

        return ['applied' => $this->appliedKeys, 'skipped' => $skipped];
    }
}
