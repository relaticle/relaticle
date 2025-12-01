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
     * Invalidate AI summaries for all related summarizable records.
     */
    public function invalidateRelatedSummaries(): void
    {
        foreach (['companies', 'people', 'opportunities'] as $relation) {
            if (method_exists($this, $relation)) {
                $this->{$relation}->each->invalidateAiSummary();
            }
        }
    }
}
