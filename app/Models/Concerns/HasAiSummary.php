<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\AiSummary;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Trait for models that can have AI-generated summaries.
 */
trait HasAiSummary
{
    /**
     * Get the AI summary for this record.
     *
     * @return MorphOne<AiSummary, $this>
     */
    public function aiSummary(): MorphOne
    {
        return $this->morphOne(AiSummary::class, 'summarizable');
    }

    /**
     * Invalidate (delete) the cached AI summary for this record.
     */
    public function invalidateAiSummary(): void
    {
        $this->aiSummary()->delete();
    }
}
