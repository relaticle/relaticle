<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Trait for models that are related to summarizable records (Notes, Tasks).
 * When these records change, the summaries of their related entities should be invalidated.
 */
trait InvalidatesRelatedAiSummaries
{
    /**
     * Relations whose summaries are invalidated when this record changes.
     * Single source of truth — also consumed when eager-loading before delete.
     *
     * @return list<string>
     */
    public static function summaryRelations(): array
    {
        return ['companies', 'people', 'opportunities'];
    }

    /**
     * Invalidate AI summaries for all related summarizable records.
     */
    public function invalidateRelatedSummaries(): void
    {
        foreach (self::summaryRelations() as $relation) {
            if (method_exists($this, $relation)) {
                $this->{$relation}->each->invalidateAiSummary();
            }
        }
    }
}
