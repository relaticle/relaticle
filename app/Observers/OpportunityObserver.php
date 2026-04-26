<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Opportunity;
use App\Observers\Concerns\TagsFirstCrmData;

final readonly class OpportunityObserver
{
    use TagsFirstCrmData;

    public function created(Opportunity $opportunity): void
    {
        $this->tagFirstCrmDataIfNeeded($opportunity);
    }

    public function saved(Opportunity $opportunity): void
    {
        $opportunity->invalidateAiSummary();
    }
}
